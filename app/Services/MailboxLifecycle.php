<?php

namespace App\Services;

use App\Events\MailboxAccessRevoked;
use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\MailInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class MailboxLifecycle
{
    public const ACTIONS = [
        'block' => ['Bloquear caixa', 'Interrompe o acesso e o envio desta caixa para todos os usuários. Novos recebimentos podem ficar pendentes no processamento. Mensagens e permissões existentes são preservadas. Não altera o DNS.'],
        'unblock' => ['Desbloquear caixa', 'Reativa a caixa com as permissões que ainda estiverem cadastradas. Recebimentos retidos precisam ser conferidos na atividade.'],
        'revoke' => ['Revogar acessos', 'Retira todas as permissões desta caixa e invalida seus convites pendentes. A caixa continua recebendo e preserva suas mensagens. Novos acessos exigem concessão explícita.'],
        'delete' => ['Excluir caixa', 'Retira a caixa da lista normal, bloqueia seu uso e revoga seus acessos. Mensagens, anexos e endereço são preservados. A exclusão é recuperável e não altera o DNS.'],
        'restore' => ['Restaurar caixa', 'Recupera o cadastro bloqueado, sem conceder acesso. Confira as permissões e desbloqueie a caixa separadamente. Mensagens e anexos permanecem preservados.'],
    ];

    public function available(Mailbox $box): array
    {
        return $box->deleted_at ? ['restore'] : [$box->active ? 'block' : 'unblock', 'revoke', 'delete'];
    }

    public function state(Mailbox $box): string
    {
        return hash('sha256', json_encode([$box->id, $box->active, $box->deleted_at, $box->updated_at]));
    }

    public function apply(User $actor, int $id, string $action, string $state): void
    {
        abort_unless($actor->master, 403);
        DB::transaction(function () use ($actor, $id, $action, $state) {
            $box = Mailbox::lockForUpdate()->findOrFail($id);
            abort_unless(hash_equals($this->state($box), $state) && in_array($action, $this->available($box), true), 409, 'O estado mudou. Volte à lista e confira novamente.');
            if (in_array($action, ['revoke', 'delete'], true)) {
                // Keep the grant rows for audit; no implicit access returns on restore.
                MailboxGrant::where('mailbox_id', $box->id)->update(['can_read' => false, 'can_send' => false, 'can_manage' => false, 'view_sensitive' => false]);
                MailInvite::where('mailbox_id', $box->id)->whereNull('accepted_at')->where('expires_at', '>', now())->update(['expires_at' => now()]);
                event(new MailboxAccessRevoked($box));
            }
            $changes = match ($action) {
                'block' => ['active' => false],
                'unblock' => ['active' => true],
                'delete' => ['active' => false, 'deleted_at' => now()],
                'restore' => ['active' => false, 'deleted_at' => null],
                default => [],
            };
            $box->forceFill($changes)->save();
            app(Access::class)->audit($actor, 'mailbox.'.$action, $box->id, (string) $box->id);
        }, 3);
    }
}
