<?php

namespace App\Http\Controllers;

use App\Jobs\ScanAttachment;
use App\Models\Attachment;
use App\Models\Message;
use App\Models\User;
use App\Services\Access;
use App\Services\Attachments;
use App\Services\MessageContent;
use App\Services\OutgoingMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MailController extends Controller
{
    public function __construct(private Access $access, private MessageContent $content) {}

    private function message(Request $r, string $id, string $ability = 'read'): Message
    {
        return Message::whereIn('mailbox_id', $this->access->mailboxes($r->user(), $ability)->select('id'))->where(fn ($q) => $q->where('status', '!=', 'draft')->orWhere('author_id', $r->user()->id))->findOrFail($id);
    }

    public function index(Request $r)
    {
        $boxes = $this->access->mailboxes($r->user())->with('domain.product.organization')->orderBy('name')->get();
        // URLs pin each tab to its own mailbox; a remembered choice never overrides them.
        // Revalidate the session hint against current grants on every request.
        $box = $r->filled('box') ? $boxes->firstWhere('id', $r->integer('box')) : ($boxes->firstWhere('id', (int) $r->session()->get('mail.active_box')) ?? $boxes->first());
        abort_if($r->filled('box') && ! $box, 404);
        $folder = (string) $r->input('folder', 'inbox');
        abort_unless(in_array($folder, ['inbox', 'drafts', 'sent', 'archive', 'trash']), 422);
        $q = Message::where('mailbox_id', $box?->id ?? 0)->where('folder', $folder);
        // Drafts remain private to their author even in a shared mailbox.
        $q->where(fn ($q) => $q->where('status', '!=', 'draft')->orWhere('author_id', $r->user()->id));
        $search = (string) $r->session()->get('search.'.($box?->id ?? 0), '');
        foreach (array_slice($this->content->words($search), 0, 5) as $word) {
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('message_search')->whereColumn('message_search.message_id', 'messages.id')->where('digest', $this->content->digest($box->id, $word)));
        }
        $messages = $q->withCount('attachments')->latest()->paginate(25)->withQueryString()->appends(['box' => $box?->id, 'folder' => $folder]);
        $selected = $r->filled('message') ? $this->message($r, (string) $r->input('message')) : null;
        if ($selected) {
            abort_unless($selected->mailbox_id === $box?->id, 404);
            abort_if($selected->status === 'draft' && $selected->author_id !== $r->user()->id, 404);
            $selected->load('attachments');
            $this->access->audit($r->user(), 'message.viewed', $selected->mailbox_id, $selected->id);
        }
        $counts = $box ? Message::where('mailbox_id', $box->id)->where(fn ($q) => $q->where('status', '!=', 'draft')->orWhere('author_id', $r->user()->id))->selectRaw('folder, COUNT(*) AS total')->groupBy('folder')->pluck('total', 'folder') : collect();

        $canSend = $box && $this->access->allowed($r->user(), $box->id, 'send');
        $operators = collect();
        if ($selected && $canSend && $selected->direction === 'inbound') {
            $operators = User::whereIn('id', $box->grants()->where('can_read', true)->where('can_send', true)->select('user_id'))->where('active', true)->limit(100)->get()->filter(fn ($u) => $this->access->allowed($u, $box->id, 'send'));
        }

        if ($box) {
            $r->session()->put('mail.active_box', $box->id);
        } else {
            $r->session()->forget('mail.active_box');
        }

        return view('mail.index', compact('boxes', 'box', 'messages', 'selected', 'folder', 'counts', 'search', 'canSend', 'operators'));
    }

    public function search(Request $r)
    {
        $box = $this->access->mailbox($r->user(), $r->integer('box'));
        $r->validate(['q' => 'nullable|string|max:100']);
        $r->session()->put('search.'.$box->id, (string) $r->input('q', ''));

        return redirect('/mail?box='.$box->id.'&folder='.rawurlencode($r->input('folder', 'inbox')));
    }

    public function create(Request $r)
    {
        $box = $this->access->mailbox($r->user(), $r->integer('mailbox_id'), 'send');
        $this->access->mailbox($r->user(), $box->id);
        $parent = $r->filled('reply_to') ? $this->message($r, (string) $r->input('reply_to')) : null;
        abort_if($parent && ($parent->mailbox_id !== $box->id || $parent->direction !== 'inbound'), 404);
        $target = $parent?->reply_to ?: $parent?->sender;
        if ($target && preg_match('/<([^<>]+)>$/D', $target, $match)) {
            $target = $match[1];
        }
        $dest = $target && filter_var($target, FILTER_VALIDATE_EMAIL) ? [$target] : [];
        $m = DB::transaction(function () use ($parent, $box, $r, $dest) {
            if ($parent) {
                $parent = Message::whereKey($parent->id)->lockForUpdate()->firstOrFail();
                abort_if($parent->assigned_to && $parent->assigned_to !== $r->user()->id, 409, 'Esta conversa está com outro responsável. Transfira a responsabilidade antes de responder.');
                $parent->update(['assigned_to' => $r->user()->id, 'version' => $parent->version + 1]);
                $existing = Message::where('reply_source_id', $parent->id)->where('author_id', $r->user()->id)->whereIn('status', ['draft', 'queued'])->first();
                if ($existing) {
                    return $existing;
                }
            }

            return Message::create(['mailbox_id' => $box->id, 'author_id' => $r->user()->id, 'reply_source_id' => $parent?->id, 'thread_id' => $parent?->thread_id ?? (string) Str::uuid(), 'direction' => 'outbound', 'folder' => 'drafts', 'status' => 'draft', 'subject' => $parent ? 'Re: '.$parent->subject : '', 'sender' => $box->address, 'recipients' => ['to' => $dest, 'cc' => [], 'bcc' => []], 'body_text' => '', 'in_reply_to' => $parent?->rfc_message_id]);
        });

        return redirect('/mail?box='.$box->id.'&folder=drafts&message='.$m->id);
    }

    public function save(Request $r, string $id)
    {
        $m = $this->message($r, $id, 'send');
        $d = $r->validate(['version' => 'required|integer|min:1', 'subject' => 'required|string|max:500', 'body_text' => 'required|string|max:100000', 'to' => 'required|string|max:2000', 'cc' => 'nullable|string|max:2000', 'bcc' => 'nullable|string|max:2000']);
        $recipients = [];
        foreach (['to', 'cc', 'bcc'] as $k) {
            $list = array_filter(array_map('trim', explode(',', $d[$k] ?? '')));
            abort_if(count($list) > 10, 422, 'Máximo de 10 destinatários por campo.');
            foreach ($list as $email) {
                abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL) && ! preg_match('/[\r\n]/', $email), 422, 'Endereço de e-mail inválido.');
            } $recipients[$k] = array_values(array_unique(array_map('strtolower', $list)));
        }
        $savedVersion = DB::transaction(function () use ($m, $r, $d, $recipients) {
            $m = Message::lockForUpdate()->findOrFail($m->id);
            abort_unless($m->status === 'draft' && $m->author_id === $r->user()->id && $m->version === $r->integer('version'), 409, 'Rascunho atualizado em outra janela. Reabra para não sobrescrever.');
            $m->update(['subject' => $d['subject'], 'body_text' => $d['body_text'], 'recipients' => $recipients, 'version' => $m->version + 1]);
            $this->content->index($m);

            return $m->version;
        });

        if ($r->expectsJson()) {
            return response()->json(['saved' => true, 'version' => $savedVersion]);
        }

        return back()->with('status', 'Rascunho salvo. Nada foi enviado.');
    }

    public function send(Request $r, string $id, OutgoingMail $service)
    {
        $r->validate(['version' => 'required|integer']);
        $m = $this->message($r, $id, 'send');
        $service->queue($m, $r->user(), $r->integer('version'));

        return redirect('/mail?box='.$m->mailbox_id.'&folder=drafts&message='.$m->id)->with('status', 'Na fila. Acompanhe o resultado; não é confirmação de entrega.');
    }

    public function move(Request $r, string $id)
    {
        $m = $this->message($r, $id);
        abort_unless($this->access->allowed($r->user(), $m->mailbox_id, 'send'), 403);
        $d = $r->validate(['folder' => 'required|in:inbox,archive,trash']);
        abort_if(in_array($m->status, ['draft', 'queued']) || $m->direction !== 'inbound', 409);
        $m->update(['folder' => $d['folder']]);
        $this->access->audit($r->user(), 'message.moved', $m->mailbox_id, $m->id);

        return redirect('/mail?box='.$m->mailbox_id)->with('status', 'Mensagem movida. Ela pode ser restaurada pela pasta de destino.');
    }

    public function read(Request $r, string $id)
    {
        $m = $this->message($r, $id);
        abort_unless($m->direction === 'inbound', 409);
        $m->update(['read_at' => $r->boolean('unread') ? null : now()]);

        return back()->with('status', 'Estado de leitura atualizado nesta caixa compartilhada.');
    }

    public function assign(Request $r, string $id)
    {
        $m = $this->message($r, $id, 'send');
        $d = $r->validate(['user_id' => 'nullable|integer', 'version' => 'required|integer|min:1']);
        $target = ! empty($d['user_id']) ? User::findOrFail($d['user_id']) : null;
        if ($target) {
            abort_unless($this->access->allowed($target, $m->mailbox_id) && $this->access->allowed($target, $m->mailbox_id, 'send'), 422, 'Responsável sem acesso a esta caixa.');
        }
        DB::transaction(function () use ($m, $r, $target) {
            $locked = Message::whereKey($m->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->direction === 'inbound' && $locked->version === $r->integer('version'), 409, 'Conversa mudou. Atualize antes de atribuir.');
            abort_if(Message::where('reply_source_id', $m->id)->where('status', 'queued')->exists(), 409, 'Existe uma resposta em processamento. Aguarde antes de transferir.');
            $locked->update(['assigned_to' => $target?->id, 'version' => $locked->version + 1]);
            $this->access->audit($r->user(), 'message.assigned', $m->mailbox_id, $m->id);
        });

        return back()->with('status', 'Responsável atualizado. Isso não concede acesso adicional à caixa.');
    }

    public function upload(Request $r, string $id, Attachments $service)
    {
        $m = $this->message($r, $id, 'send');
        abort_unless($m->author_id === $r->user()->id && $m->status === 'draft', 409);
        $r->validate(['attachment' => 'required|file|max:10240']);
        $a = $service->store($m, $r->file('attachment'));
        try {
            ScanAttachment::dispatch($a->id);
        } catch (\Throwable) { /* DB quarantine is recovered by brnmail:scan. */
        }
        $a->refresh();

        return back()->with('status', $a->status === 'clean' ? 'Anexo verificado.' : 'Anexo em quarentena. Envio e download bloqueados até a verificação.');
    }

    public function download(Request $r, string $id)
    {
        $a = Attachment::whereHas('message', fn ($q) => $q->whereIn('mailbox_id', $this->access->mailboxes($r->user())->select('id'))->where(fn ($q) => $q->where('status', '!=', 'draft')->orWhere('author_id', $r->user()->id)))->findOrFail($id);
        abort_unless($a->status === 'clean' && $a->path, 423, 'Arquivo não liberado.');
        $bytes = Crypt::decryptString(Storage::disk('local')->get($a->path));
        abort_unless(hash_equals($a->sha256, hash('sha256', $bytes)), 423);
        $this->access->audit($r->user(), 'attachment.downloaded', $a->message->mailbox_id, $a->id);

        return response()->streamDownload(fn () => print ($bytes), $a->filename, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function removeAttachment(Request $r, string $id)
    {
        $a = Attachment::findOrFail($id);
        $m = $this->message($r, $a->message_id, 'send');
        DB::transaction(function () use ($m, $r, $a) {
            $locked = Message::lockForUpdate()->findOrFail($m->id);
            abort_unless($locked->status === 'draft' && $locked->author_id === $r->user()->id, 409);
            $path = $a->path;
            $a->delete();
            if ($path) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($path));
            }
        });

        return back()->with('status', 'Anexo removido do rascunho.');
    }
}
