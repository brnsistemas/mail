<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\MailDomain;
use App\Models\MailInvite;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProviderSetting;
use App\Models\User;
use App\Services\Access;
use App\Services\AccountReactivation;
use App\Services\AdminMailboxInventory;
use App\Services\DomainStatus;
use App\Services\InvitationMailbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    private function master(Request $r): void
    {
        abort_unless($r->user()->master, 403);
    }

    public function index(Request $r, AdminMailboxInventory $inventory)
    {
        $this->master($r);

        $users = User::select('id', 'name', 'email', 'active', 'master', 'totp_confirmed_at')
            ->selectSub(DB::table('mail_audits')->selectRaw('MAX(created_at)')
                ->whereColumn('actor_id', 'users.id')->where('action', 'mfa.verified'), 'last_login_at')
            ->orderBy('name')->orderBy('id')->get();
        $boxes = Mailbox::with('domain.product.organization', 'grants')->orderBy('mail_domain_id')->orderBy('address')->get();

        return view('admin.index', ['organizations' => Organization::all(), 'products' => Product::all(), 'domains' => MailDomain::all(), 'boxes' => $boxes, 'users' => $users, 'inventory' => $inventory->rows($boxes, $users), 'events' => DB::table('webhook_events')->select('id', 'type', 'status', 'last_error', 'created_at')->latest()->limit(20)->get(), 'audits' => DB::table('mail_audits')->latest()->limit(20)->get(), 'outbox' => DB::table('mail_outbox')->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->get(), 'hasProviderKey' => (bool) ProviderSetting::valueFor('api_key')]);
    }

    public function provider(Request $r, Access $access)
    {
        $this->master($r);
        $d = $r->validate(['api_key' => 'nullable|string|max:300', 'webhook_secret' => 'nullable|string|max:300', 'test_recipients' => 'nullable|string|max:2000', 'password' => 'required|string']);
        abort_unless(Hash::check($d['password'], $r->user()->password), 422);
        $row = ProviderSetting::find(1) ?? new ProviderSetting;
        $row->id = 1;
        if (! empty($d['api_key'])) {
            abort_unless(str_starts_with($d['api_key'], 're_'), 422);
            $row->api_key = $d['api_key'];
        }
        if (! empty($d['webhook_secret'])) {
            abort_unless(str_starts_with($d['webhook_secret'], 'whsec_') && strlen(base64_decode(substr($d['webhook_secret'], 6), true) ?: '') >= 24, 422);
            $row->webhook_secret = $d['webhook_secret'];
        }
        if ($r->has('test_recipients')) {
            $emails = array_values(array_filter(array_map('trim', explode(',', $d['test_recipients'] ?? ''))));
            foreach ($emails as $email) {
                abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 422);
            } $row->test_recipients = array_map('strtolower', $emails);
        }
        $row->save();
        $access->audit($r->user(), 'provider.credentials_saved');

        return back()->with('status', 'Credenciais armazenadas cifradas. Campos vazios preservam as chaves existentes. Isso não habilita envio externo.');
    }

    public function product(Request $r)
    {
        $this->master($r);
        $d = $r->validate(['organization_id' => 'required|exists:organizations,id', 'name' => 'required|string|max:100']);
        Product::create($d);

        return back()->with('status', 'Produto criado.');
    }

    public function organization(Request $r, Access $access)
    {
        $this->master($r);
        $d = $r->validate(['name' => 'required|string|max:100', 'password' => 'required|string']);
        abort_unless(Hash::check($d['password'], $r->user()->password), 422);
        $org = Organization::create(['name' => $d['name']]);
        $access->audit($r->user(), 'organization.created', null, (string) $org->id);

        return back()->with('status', 'Empresa criada sem acesso implícito às caixas. Convide os membros e conceda as permissões.');
    }

    public function domainStatus(Request $r, DomainStatus $service, Access $access)
    {
        $this->master($r);
        $d = $r->validate(['mail_domain_id' => 'required|exists:mail_domains,id', 'provider_id' => 'required|uuid', 'password' => 'required|string']);
        abort_unless(Hash::check($d['password'], $r->user()->password), 422);
        try {
            $state = $service->refresh(MailDomain::findOrFail($d['mail_domain_id']), $d['provider_id']);
        } catch (\Throwable) {
            return back()->withErrors(['provider' => 'Não foi possível confirmar o domínio. Verifique a liberação externa, a chave e o ID no Resend. Nenhum DNS foi alterado.']);
        }
        $access->audit($r->user(), 'domain.status_checked', null, (string) $d['mail_domain_id']);

        return back()->with('status', 'Consulta ao Resend: envio '.($state['sending_verified'] ? 'verificado' : 'pendente').'; recebimento '.($state['receiving_enabled'] ? 'habilitado no provedor' : 'desabilitado no provedor').'. Entrega externa ainda exige teste real.');
    }

    public function domain(Request $r)
    {
        $this->master($r);
        $d = $r->validate(['product_id' => 'required|exists:products,id', 'domain' => ['required', 'max:200', 'regex:/^(?=.{1,200}$)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/D', 'unique:mail_domains,domain']]);
        MailDomain::create($d + ['status' => 'pending']);

        return back()->with('status', 'Domínio cadastrado como pendente. DNS não foi alterado.');
    }

    public function mailbox(Request $r)
    {
        $this->master($r);
        $d = $r->validate(['mail_domain_id' => 'required|exists:mail_domains,id', 'name' => 'required|string|max:100', 'address' => 'required|email|max:254|unique:mailboxes,address']);
        $domain = MailDomain::findOrFail($d['mail_domain_id']);
        abort_unless(str_ends_with(strtolower($d['address']), '@'.$domain->domain), 422);
        DB::transaction(function () use ($d, $r, $domain) {
            MailDomain::whereKey($domain->id)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('mail_aliases')->where('address', strtolower($d['address']))->exists(), 422, 'Endereço já reservado como alias.');
            $d['address'] = strtolower($d['address']);
            Mailbox::create($d + ['sensitive' => $r->boolean('sensitive')]);
        });

        return back()->with('status', 'Caixa criada sem acesso implícito. Conceda as permissões.');
    }

    public function alias(Request $r, Access $access)
    {
        $this->master($r);
        $d = $r->validate(['mailbox_id' => 'required|exists:mailboxes,id', 'address' => 'required|email|max:254', 'password' => 'required|string']);
        abort_unless(Hash::check($d['password'], $r->user()->password), 422);
        $box = Mailbox::with('domain')->findOrFail($d['mailbox_id']);
        $address = strtolower($d['address']);
        abort_unless(str_ends_with($address, '@'.$box->domain->domain), 422, 'O alias precisa pertencer ao domínio desta caixa.');
        DB::transaction(function () use ($box, $address, $r, $access) {
            MailDomain::whereKey($box->mail_domain_id)->lockForUpdate()->firstOrFail();
            abort_if(Mailbox::where('address', $address)->exists() || DB::table('mail_aliases')->where('address', $address)->exists(), 422, 'Endereço já utilizado.');
            DB::table('mail_aliases')->insert(['mailbox_id' => $box->id, 'address' => $address, 'created_at' => now(), 'updated_at' => now()]);
            $access->audit($r->user(), 'alias.created', $box->id);
        });

        return back()->with('status', 'Alias cadastrado para recebimento na mesma caixa. Não altera DNS nem permite um novo remetente.');
    }

    public function grant(Request $r, Access $access)
    {
        $this->master($r);
        $d = $r->validate([
            'user_id' => 'required|integer|exists:users,id',
            'mailbox_id' => 'required_without:mailbox_ids|prohibits:mailbox_ids|integer|exists:mailboxes,id',
            'mailbox_ids' => 'required_without:mailbox_id|prohibits:mailbox_id|array|min:1|max:50',
            'mailbox_ids.*' => 'required|integer|distinct|exists:mailboxes,id',
            'add_membership' => 'sometimes|boolean',
            'password' => 'required|string',
        ], [
            'mailbox_ids.required_without' => 'Selecione pelo menos uma caixa.',
            'mailbox_ids.max' => 'Selecione no máximo 50 caixas por vez.',
        ]);
        abort_unless(Hash::check($d['password'], $r->user()->password), 422);
        $ids = $d['mailbox_ids'] ?? [$d['mailbox_id']];
        DB::transaction(function () use ($d, $ids, $r, $access) {
            $boxes = Mailbox::with('domain.product')->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($boxes->count() === count($ids), 422, 'Uma das caixas selecionadas não está mais disponível.');
            $organizations = $boxes->map(fn ($box) => $box->domain->product->organization_id)->unique();
            $memberships = Membership::where('user_id', $d['user_id'])->whereIn('organization_id', $organizations)
                ->orderBy('organization_id')->lockForUpdate()->get();
            abort_if($memberships->contains(fn ($membership) => ! $membership->active), 422, 'Esta pessoa tem um vínculo revogado em uma das empresas. A concessão não foi aplicada.');
            $missing = $organizations->diff($memberships->pluck('organization_id'));
            abort_unless($missing->isEmpty() || $r->boolean('add_membership'), 422, 'Marque a opção de vincular a conta às empresas das caixas selecionadas.');
            $user = User::whereKey($d['user_id'])->lockForUpdate()->firstOrFail();
            abort_if($r->boolean('add_membership') && ! $user->active, 422, 'A conta desta pessoa está desativada.');
            foreach ($missing as $organizationId) {
                Membership::create(['organization_id' => $organizationId, 'user_id' => $user->id, 'active' => true, 'role' => 'member']);
                MailInvite::where('organization_id', $organizationId)->where('email', $user->email)
                    ->whereNull('accepted_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);
                $access->audit($r->user(), 'membership.granted', null, 'user:'.$user->id.';organization:'.$organizationId);
            }
            foreach ($boxes as $box) {
                MailboxGrant::updateOrCreate(['user_id' => $user->id, 'mailbox_id' => $box->id], [
                    'can_read' => $r->boolean('can_read'),
                    'can_send' => $r->boolean('can_send'),
                    'can_manage' => false,
                    'view_sensitive' => $box->sensitive && $r->boolean('view_sensitive'),
                ]);
                $access->audit($r->user(), 'grant.updated', $box->id, (string) $user->id);
            }
            $user->increment('security_version');
        }, 3);

        return back()->with('status', 'Permissões atualizadas nas '.count($ids).' caixas selecionadas. Sessões anteriores desse usuário exigirão novo 2FA.');
    }

    public function invite(Request $r, Access $access, InvitationMailbox $mailboxes)
    {
        $this->master($r);
        $d = $r->validate(['organization_id' => 'required|exists:organizations,id', 'email' => 'required|email|max:254',
            'mailbox_address' => 'nullable|email|max:254', 'reuse_mailbox' => 'sometimes|boolean', 'password' => 'required|string']);
        if (! Hash::check($d['password'], $r->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Confirme sua senha atual para autorizar a caixa deste convite.']);
        }
        $d['email'] = strtolower(trim($d['email']));
        $token = bin2hex(random_bytes(32));
        $i = DB::transaction(function () use ($d, $r, $token, $mailboxes, $access) {
            // Serialize invitations per company, including repeated submissions.
            Organization::whereKey($d['organization_id'])->lockForUpdate()->firstOrFail();
            if (User::where('email', $d['email'])->exists()) {
                throw ValidationException::withMessages(['email' => 'Esta pessoa já tem conta. Use Permissões por caixa, sem enviar outro convite.']);
            }
            if (MailInvite::where('email', $d['email'])->whereNull('accepted_at')->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['email' => 'Já existe um convite pendente para esta pessoa. Nenhum convite ou caixa duplicado foi criado.']);
            }
            $box = $mailboxes->prepare($r->user(), (int) $d['organization_id'], $d['mailbox_address'] ?? $d['email'], $r->boolean('reuse_mailbox'));
            $invite = MailInvite::create(['organization_id' => $d['organization_id'], 'email' => $d['email'],
                'mailbox_id' => $box->id, 'mailbox_address' => $box->address,
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(48)]);
            $access->audit($r->user(), 'invite.created', $box->id, $invite->id);

            return $invite;
        }, 3);

        return view('admin.invite', ['link' => url('/invite/'.$i->id).'#'.$token, 'mailboxAddress' => $i->mailbox_address]);
    }

    public function revokeMember(Request $r, Access $access)
    {
        $this->master($r);
        $d = $r->validate(['user_id' => 'required|exists:users,id', 'organization_id' => 'required|exists:organizations,id', 'password' => 'required|string']);
        abort_unless(Hash::check($d['password'], $r->user()->password) && $d['user_id'] != $r->user()->id, 422);
        DB::transaction(function () use ($d) {
            Membership::where('user_id', $d['user_id'])->where('organization_id', $d['organization_id'])->update(['active' => false]);
            User::whereKey($d['user_id'])->increment('security_version');
        });
        $access->audit($r->user(), 'membership.revoked', null, (string) $d['user_id']);

        return back()->with('status', 'Acesso à empresa revogado. Mensagens preservadas.');
    }

    public function showInvite(Request $r, string $id)
    {
        $invite = MailInvite::find($id);
        if (! $invite) {
            return $this->unavailableInvite($r, 'invalid', 404);
        }
        if ($invite->accepted_at || $invite->expires_at->isPast()) {
            return $this->unavailableInvite($r, $invite->accepted_at ? 'used' : 'expired');
        }
        $ready = hash_equals($invite->token_hash, (string) $r->session()->get('mail_invites.'.$id, ''));

        return view('auth.invite', [
            'id' => $id, 'ready' => $ready,
            'email' => $ready ? $invite->email : null,
            'organization' => $ready ? Organization::findOrFail($invite->organization_id)->name : null,
            'reactivation' => $ready && (bool) $invite->reactivation_user_id,
            'existingAccount' => $ready && ! $invite->reactivation_user_id && User::where('email', $invite->email)->exists(),
            'mailboxAddress' => $ready ? $invite->mailbox_address : null,
        ]);
    }

    public function openInvite(Request $r, string $id)
    {
        $d = $r->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']], [
            'token.*' => 'Abra o link completo do convite para continuar.',
        ]);
        $invite = MailInvite::find($id);
        if (! $invite) {
            return $this->unavailableInvite($r, 'invalid', 404);
        }
        $hash = hash('sha256', $d['token']);
        if (! hash_equals($invite->token_hash, $hash)) {
            return $this->unavailableInvite($r, 'invalid');
        }
        if ($invite->accepted_at || $invite->expires_at->isPast()) {
            return $this->unavailableInvite($r, $invite->accepted_at ? 'used' : 'expired');
        }
        // Only the verified digest stays in this browser session, scoped to the invitation.
        $r->session()->put('mail_invites.'.$id, $hash);

        return response()->noContent();
    }

    public function accept(Request $r, string $id)
    {
        $invite = MailInvite::find($id);
        if (! $invite) {
            return $this->unavailableInvite($r, 'invalid', 404);
        }
        if ($invite->accepted_at || $invite->expires_at->isPast()) {
            return $this->unavailableInvite($r, $invite->accepted_at ? 'used' : 'expired');
        }
        // Older pages can still submit the fragment directly. Never flash it back.
        $token = $r->input('token');
        $hash = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)
            ? hash('sha256', $token) : $r->session()->get('mail_invites.'.$id);
        if (! is_string($hash)) {
            throw ValidationException::withMessages(['invite' => 'Abra o link completo do convite para continuar.']);
        }
        $d = $r->validate(['name' => 'required|string|max:100', 'password' => 'required|string|min:14|max:150|confirmed'], [
            'name.required' => 'Informe seu nome.',
            'password.required' => 'Informe sua senha.',
            'password.min' => 'A senha deve ter pelo menos 14 caracteres.',
            'password.confirmed' => 'A confirmação da senha não confere.',
        ]);
        $createdUser = null;
        $unavailable = DB::transaction(function () use ($d, $id, $hash, &$createdUser) {
            $i = MailInvite::lockForUpdate()->find($id);
            if (! $i || ! hash_equals($i->token_hash, $hash)) {
                return 'invalid';
            }
            if ($i->accepted_at || $i->expires_at->isPast()) {
                return $i->accepted_at ? 'used' : 'expired';
            }
            if ($i->reactivation_user_id) {
                $createdUser = app(AccountReactivation::class)->accept($i, $d['password']);
                if (! $createdUser) {
                    return 'invalid';
                }
                $i->update(['accepted_at' => now()]);

                return null;
            }
            $u = User::where('email', $i->email)->lockForUpdate()->first();
            if ($u) {
                if (! $u->active) {
                    throw ValidationException::withMessages(['invite' => 'Esta conta está desativada. Peça ao administrador para revisar o acesso.']);
                }
                if (! Hash::check($d['password'], $u->password)) {
                    throw ValidationException::withMessages(['password' => 'Use a senha atual da sua conta no BRN Mail.']);
                }
            } else {
                $u = User::create(['email' => $i->email, 'name' => $d['name'], 'password' => $d['password']]);
                $createdUser = $u;
            }
            $membership = Membership::where('organization_id', $i->organization_id)->where('user_id', $u->id)->lockForUpdate()->first();
            if ($membership && ! $membership->active) {
                throw ValidationException::withMessages(['invite' => 'O acesso a esta empresa foi revogado. Peça ao administrador para revisá-lo.']);
            }
            Membership::firstOrCreate(['organization_id' => $i->organization_id, 'user_id' => $u->id], ['active' => true, 'role' => 'member']);
            app(InvitationMailbox::class)->grant($i, $u);
            $i->update(['accepted_at' => now()]);

            return null;
        });

        if ($unavailable) {
            return $this->unavailableInvite($r, $unavailable);
        }

        $r->session()->forget('mail_invites.'.$id);

        if ($createdUser) {
            // Start a fresh session only after the invitation transaction commits.
            // Never inherit another account's MFA or mailbox selection.
            $r->session()->invalidate();
            Auth::login($createdUser);
            $r->session()->regenerate();
            $r->session()->put('credential_version', (int) $createdUser->credential_version);

            return redirect('/two-factor')->with('status', $invite->reactivation_user_id ? 'Senha definida. Configure seu novo autenticador para continuar.' : 'Conta criada. Configure seu autenticador para continuar.');
        }

        return redirect('/login')->with('status', $invite->mailbox_id
            ? 'Convite aceito. Sua caixa está liberada. Entre com sua conta e confirme o autenticador.'
            : 'Convite aceito. Entre com sua conta para continuar. O acesso às caixas depende de concessão do administrador.');
    }

    private function unavailableInvite(Request $r, string $state, int $status = 410)
    {
        $message = match ($state) {
            'used' => 'Este convite já foi aceito. Entre com o e-mail e a senha cadastrados. Você não precisa criar outra conta.',
            'expired' => 'O prazo deste convite terminou. Peça ao administrador um novo link privado.',
            default => 'Este convite não é válido. Confira se recebeu o link completo ou peça ajuda ao administrador.',
        };

        if ($r->expectsJson()) {
            return response()->json(['code' => 'invite_'.$state, 'message' => $message], $status);
        }

        return response()->view('auth.invite-status', ['state' => $state, 'message' => $message], $status);
    }
}
