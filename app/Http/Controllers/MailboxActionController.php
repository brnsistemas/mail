<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\MailboxLifecycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MailboxActionController extends Controller
{
    public function show(Request $request, MailboxLifecycle $service, int $id, string $operation)
    {
        abort_unless($request->user()->master, 403);
        $box = Mailbox::with('domain.product.organization')->findOrFail($id);
        abort_unless(in_array($operation, $service->available($box), true), 409);

        return response()->view('auth.confirm-action', [
            'title' => $service::ACTIONS[$operation][0], 'impact' => $service::ACTIONS[$operation][1],
            'resource' => $box->address, 'context' => $box->domain->product->organization->name,
            'state' => $service->state($box), 'target' => '/admin/mailboxes/'.$id.'/'.$operation, 'back' => '/admin/mailboxes',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, MailboxLifecycle $service, int $id, string $operation)
    {
        abort_unless($request->user()->master, 403);
        $data = $request->validate(['password' => 'required|string', 'confirm' => 'accepted', 'state' => 'required|string|size:64']);
        abort_unless(Hash::check($data['password'], $request->user()->password), 422, 'Confirme sua senha atual.');
        $service->apply($request->user(), $id, $operation, $data['state']);

        return redirect('/admin/mailboxes')->with('status', 'Ação aplicada somente à caixa selecionada. Mensagens preservadas.');
    }
}
