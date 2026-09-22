<?php

namespace App\Services;

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\MailDomain;
use App\Models\MailInvite;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvitationMailbox
{
    /** Called within the invitation transaction, after the administrator confirms access. */
    public function prepare(User $operator, int $organization, string $address, bool $reuse = false): Mailbox
    {
        abort_unless($operator->active && $operator->master, 403);
        $address = strtolower(trim($address));
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $this->refuse('Informe um endereço válido para a caixa.');
        }
        $domain = MailDomain::where('domain', substr(strrchr($address, '@'), 1))
            ->whereHas('product', fn ($q) => $q->where('organization_id', $organization))->lockForUpdate()->first();
        if (! $domain) {
            $this->refuse('O domínio da caixa precisa estar cadastrado na empresa selecionada. Para um login externo, informe também a caixa da empresa.');
        }
        if (! in_array($domain->status, ['verified', 'local'], true)) {
            $this->refuse('Confirme a configuração do domínio antes de convidar o colaborador.');
        }
        if (DB::table('mail_aliases')->where('address', $address)->exists()) {
            $this->refuse('Este endereço é um alias. Selecione uma caixa independente; nenhum acesso foi alterado.');
        }
        $box = Mailbox::where('address', $address)->lockForUpdate()->first();
        if ($box) {
            if ((int) $box->mail_domain_id !== (int) $domain->id || ! $box->active || $box->sensitive) {
                $this->refuse('Esta caixa exige revisão em Permissões por caixa. Caixas desativadas ou sensíveis não são liberadas pelo convite.');
            }
            if (! $reuse) {
                $this->refuse('A caixa já existe. Confirme o acesso ao histórico dessa caixa para incluí-la no convite.');
            }
        } else {
            $box = Mailbox::create(['mail_domain_id' => $domain->id, 'address' => $address,
                'name' => strstr($address, '@', true), 'active' => true, 'sensitive' => false]);
            app(Access::class)->audit($operator, 'invite.mailbox_created', $box->id);
        }

        return $box;
    }

    /** The stored invitation binds the grant to one exact mailbox; never trust submitted IDs. */
    public function grant(MailInvite $invite, User $user, ?User $operator = null): void
    {
        if (! $invite->mailbox_id) {
            return; // Older invitations and credential reactivation retain their existing scope.
        }
        $box = Mailbox::whereKey($invite->mailbox_id)->lockForUpdate()->first();
        if (! $box || ! $box->active || $box->sensitive || $box->address !== $invite->mailbox_address
            || (int) $box->domain->product->organization_id !== (int) $invite->organization_id
            || ! str_ends_with($box->address, '@'.$box->domain->domain)
            || ! $user->active || $user->email !== $invite->email
            || ! Membership::where('user_id', $user->id)->where('organization_id', $invite->organization_id)->where('active', true)->exists()) {
            $this->refuse('A caixa deste convite não está disponível. Peça ao administrador para revisar o acesso.');
        }
        $existing = MailboxGrant::where('user_id', $user->id)->where('mailbox_id', $box->id)->first();
        if ($existing) {
            if (! $existing->can_read || ! $existing->can_send) {
                $this->refuse('As permissões desta caixa foram alteradas. Peça ao administrador para revisar o acesso.');
            }

            return;
        }
        MailboxGrant::create(['user_id' => $user->id, 'mailbox_id' => $box->id,
            'can_read' => true, 'can_send' => true, 'can_manage' => false, 'view_sensitive' => false]);
        $user->increment('security_version');
        app(Access::class)->audit($operator ?? $user, 'invite.mailbox_granted', $box->id, $invite->id);
    }

    /** Explicit trusted-operator repair for an already accepted, previously boxless invitation. */
    public function repair(int $operatorId, string $email, int $organization): Mailbox
    {
        return DB::transaction(function () use ($operatorId, $email, $organization) {
            $operator = User::whereKey($operatorId)->where('active', true)->where('master', true)->firstOrFail();
            $user = User::where('email', strtolower(trim($email)))->lockForUpdate()->firstOrFail();
            if (! $user->active || $user->master
                || ! Membership::where('user_id', $user->id)->where('organization_id', $organization)->where('active', true)->exists()) {
                $this->refuse('A correção exige um colaborador ativo e vínculo ativo na empresa.');
            }
            $invite = MailInvite::where('email', $user->email)->where('organization_id', $organization)
                ->whereNotNull('accepted_at')->whereNull('reactivation_user_id')->latest('accepted_at')->lockForUpdate()->firstOrFail();
            if ($invite->mailbox_id) {
                $this->grant($invite, $user, $operator);

                return Mailbox::findOrFail($invite->mailbox_id);
            }
            // Never adopt a shared/existing mailbox while repairing a legacy identity.
            $box = $this->prepare($operator, $organization, $user->email);
            $invite->update(['mailbox_id' => $box->id, 'mailbox_address' => $box->address]);
            $this->grant($invite, $user, $operator);
            app(Access::class)->audit($operator, 'invite.mailbox_repaired', $box->id, (string) $user->id);

            return $box;
        }, 3);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['mailbox_address' => $message]);
    }
}
