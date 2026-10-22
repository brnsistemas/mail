<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\MailInvite;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProviderSetting;
use App\Models\User;
use App\Services\AdminMailboxInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only administration. Viewing metadata never grants mailbox access. */
class AdminPageController extends Controller
{
    public const SECTIONS = [
        'overview' => ['Visão geral', 'Acompanhe sua operação e escolha o que deseja gerenciar.', 'Início'],
        'companies' => ['Empresas', 'Organize as empresas que usam o BRN Mail.', 'Estrutura'],
        'products' => ['Produtos', 'Vincule cada produto à empresa responsável.', 'Estrutura'],
        'domains' => ['Domínios', 'Cadastre domínios e confira o estado no provedor.', 'Estrutura'],
        'mailboxes' => ['Caixas de e-mail', 'Consulte caixas, responsáveis e último login com 2FA.', 'Estrutura'],
        'aliases' => ['Aliases', 'Endereços adicionais que recebem em uma caixa existente.', 'Estrutura'],
        'users' => ['Pessoas', 'Consulte as contas de acesso e seus autenticadores.', 'Acessos'],
        'permissions' => ['Permissões por caixa', 'Conceda acesso explícito às contas já existentes.', 'Acessos'],
        'invites' => ['Convites', 'Prepare a caixa e convide uma nova pessoa.', 'Acessos'],
        'integration' => ['Integração', 'Confira o envio e gerencie as credenciais do Resend.', 'Operação'],
        'activity' => ['Atividade', 'Acompanhe filas, recebimento e auditoria.', 'Operação'],
    ];

    public function index(Request $request, AdminMailboxInventory $inventory, string $section = 'overview')
    {
        abort_unless($request->user()->master, 403);
        abort_unless(isset(self::SECTIONS[$section]), 404);
        $filters = $request->validate([
            'q' => 'nullable|string|max:100', 'organization' => 'nullable|integer|exists:organizations,id',
            'state' => 'nullable|in:active,inactive,deleted', 'page' => 'nullable|integer|min:1|max:100000',
            'events_page' => 'nullable|integer|min:1|max:100000', 'audits_page' => 'nullable|integer|min:1|max:100000',
        ]);
        $filters = array_intersect_key($filters, array_flip(['q', 'organization', 'state']));
        $q = trim($filters['q'] ?? '');
        $organization = $filters['organization'] ?? null;
        $page = fn ($query, $name = 'page') => $query->paginate(15, ['*'], $name)->appends($filters);
        $people = fn () => User::select('id', 'name', 'email', 'active', 'master', 'totp_confirmed_at')
            ->selectSub(DB::table('mail_audits')->selectRaw('MAX(created_at)')
                ->whereColumn('actor_id', 'users.id')->where('action', 'mfa.verified'), 'last_login_at');
        $boxQuery = fn () => Mailbox::with('domain.product.organization', 'grants')
            ->when(($filters['state'] ?? '') === 'deleted' && $section === 'mailboxes', fn ($b) => $b->whereNotNull('deleted_at'), fn ($b) => $b->whereNull('deleted_at'))
            ->when($organization, fn ($b) => $b->whereHas('domain.product', fn ($p) => $p->where('organization_id', $organization)))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('address', 'like', '%'.$q.'%')->orWhere('name', 'like', '%'.$q.'%')))
            ->when(in_array($filters['state'] ?? '', ['active', 'inactive']), fn ($b) => $b->where('active', $filters['state'] === 'active'))
            ->orderBy('address')->orderBy('id');
        $data = ['section' => $section, 'sections' => self::SECTIONS, 'filters' => $filters];

        switch ($section) {
            case 'overview':
                $data['counts'] = ['companies' => Organization::count(), 'domains' => MailDomain::count(),
                    'mailboxes' => Mailbox::whereNull('deleted_at')->count(), 'users' => User::count(),
                    'active' => Mailbox::where('active', true)->count(),
                    'pending' => DB::table('mail_outbox')->whereIn('status', ['pending', 'retry', 'sending'])->count()];
                break;
            case 'companies':
                $data['records'] = $page(Organization::when($q !== '', fn ($b) => $b->where('name', 'like', '%'.$q.'%'))->orderBy('name')->orderBy('id'));
                break;
            case 'products':
                $data['organizations'] = Organization::orderBy('name')->get();
                $data['records'] = $page(Product::with('organization')->when($organization, fn ($b) => $b->where('organization_id', $organization))
                    ->when($q !== '', fn ($b) => $b->where('name', 'like', '%'.$q.'%'))->orderBy('name')->orderBy('id'));
                break;
            case 'domains':
                $data['products'] = Product::with('organization')->orderBy('name')->get();
                $data['domains'] = MailDomain::orderBy('domain')->get();
                $data['records'] = $page(MailDomain::with('product.organization')->when($q !== '', fn ($b) => $b->where('domain', 'like', '%'.$q.'%'))->orderBy('domain')->orderBy('id'));
                break;
            case 'mailboxes':
                $data['organizations'] = Organization::orderBy('name')->get();
                $data['domains'] = MailDomain::orderBy('domain')->get();
                $data['boxes'] = $page($boxQuery());
                $ids = $data['boxes']->getCollection()->flatMap(fn ($box) => $box->grants->pluck('user_id'))->unique();
                $data['inventory'] = $inventory->rows($data['boxes']->getCollection(), $people()->whereIn('id', $ids)->get());
                break;
            case 'aliases':
                $data['boxes'] = Mailbox::whereNull('deleted_at')->orderBy('address')->get(['id', 'address']);
                $data['records'] = $page(DB::table('mail_aliases')->join('mailboxes', 'mail_aliases.mailbox_id', '=', 'mailboxes.id')
                    ->select('mail_aliases.id', 'mail_aliases.address', 'mailboxes.address as destination')
                    ->when($q !== '', fn ($b) => $b->where('mail_aliases.address', 'like', '%'.$q.'%'))->orderBy('mail_aliases.address'));
                break;
            case 'users':
                $data['users'] = $page($people()->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')))
                    ->when($filters['state'] ?? null, fn ($b, $state) => $b->where('active', $state === 'active'))->orderBy('name')->orderBy('id'));
                $data['organizations'] = Organization::orderBy('name')->get();
                $data['userOptions'] = User::orderBy('name')->get(['id', 'name', 'email']);
                break;
            case 'permissions':
                $data['organizations'] = Organization::orderBy('name')->get();
                // A permission form is scoped to one company, with no preselected boxes.
                $data['boxes'] = $organization ? $boxQuery()->get() : collect();
                $data['users'] = User::orderBy('name')->get(['id', 'name', 'email']);
                break;
            case 'invites':
                $data['organizations'] = Organization::orderBy('name')->get();
                $data['records'] = $page(MailInvite::select('id', 'organization_id', 'email', 'mailbox_address', 'accepted_at', 'expires_at', 'created_at')
                    ->when($organization, fn ($b) => $b->where('organization_id', $organization))
                    ->when($q !== '', fn ($b) => $b->where('email', 'like', '%'.$q.'%'))->orderByDesc('created_at')->orderByDesc('id'));
                break;
            case 'integration':
                $data['hasProviderKey'] = (bool) ProviderSetting::valueFor('api_key');
                break;
            case 'activity':
                $data['outbox'] = DB::table('mail_outbox')->select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->get();
                $data['events'] = $page(DB::table('webhook_events')->select('id', 'type', 'status', 'last_error', 'created_at')->latest()->orderByDesc('id'), 'events_page');
                $data['audits'] = $page(DB::table('mail_audits')->select('id', 'actor_id', 'action', 'created_at')->latest()->orderByDesc('id'), 'audits_page');
                break;
        }

        return response()->view('admin.index', $data)->header('Cache-Control', 'private, no-store');
    }
}
