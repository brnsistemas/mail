<?php

namespace App\Events;

use App\Models\Mailbox;

/** Synchronous listeners run inside the mailbox transaction. */
final class MailboxAccessRevoked
{
    public function __construct(public Mailbox $mailbox) {}
}
