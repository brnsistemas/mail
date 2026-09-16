<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BootstrapMasterTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'Synthetic-Bootstrap-Only!2026';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.debug' => false,
            'app.url' => 'https://mail.example.com',
            'brnmail.bootstrap_url' => 'https://mail.example.com',
            'brnmail.transport' => 'resend',
            'brnmail.local_demo' => false,
        ]);
    }

    private function prompt(string $password = self::PASSWORD, string $confirmation = self::PASSWORD)
    {
        return $this->artisan('brnmail:bootstrap-master')
            ->expectsQuestion('Nome do primeiro administrador', 'Administrador · QA')
            ->expectsQuestion('E-mail de login do master', 'CEO@EXAMPLE.TEST')
            ->expectsQuestion('Senha nova (14 a 72 bytes; não será exibida)', $password)
            ->expectsQuestion('Confirme a senha nova', $confirmation);
    }

    public function test_first_master_has_hashed_password_requires_mfa_and_has_no_implicit_mailbox_access(): void
    {
        $this->prompt()->expectsConfirmation('Criar este primeiro master? O segundo fator continuará obrigatório', 'yes')
            ->doesntExpectOutputToContain(self::PASSWORD)->assertSuccessful();
        $user = User::sole();
        $this->assertSame('ceo@example.test', $user->email);
        $this->assertTrue($user->master);
        $this->assertTrue($user->active);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotSame(self::PASSWORD, $user->getRawOriginal('password'));
        $this->assertNull($user->totp_secret);
        $this->assertNull($user->totp_confirmed_at);
        $this->assertNull($user->recovery_hashes);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(0, app(Access::class)->mailboxes($user)->count());
        foreach (['mailboxes', 'memberships', 'mailbox_grants', 'mail_invites', 'mail_outbox'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('mail_audits', ['actor_id' => $user->id, 'action' => 'identity.first_master_created']);
        $audit = json_encode(DB::table('mail_audits')->get());
        $this->assertStringNotContainsString(self::PASSWORD, $audit);
        $this->assertStringNotContainsString($user->email, $audit);
        $this->actingAs($user)->get('/admin')->assertRedirect('/two-factor');
        Http::assertNothingSent();
    }

    public function test_existing_user_is_never_promoted_or_reset(): void
    {
        $user = User::factory()->create();
        $before = $user->fresh()->getRawOriginal();
        $this->artisan('brnmail:bootstrap-master')->assertFailed();
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('mail_audits', 0);
    }

    public function test_declining_confirmation_creates_nothing_and_releases_lock(): void
    {
        $this->prompt()->expectsConfirmation('Criar este primeiro master? O segundo fator continuará obrigatório', 'no')->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(1, (int) DB::selectOne("SELECT IS_FREE_LOCK('brnmail.first-master') AS free")->free);
    }

    public function test_password_mismatch_or_bcrypt_truncation_length_is_refused(): void
    {
        $this->prompt(self::PASSWORD, 'Different-Synthetic-Password!')->assertFailed();
        $this->prompt(str_repeat('a', 73), str_repeat('a', 73))->assertFailed();
        $this->prompt('short', 'short')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_audit_failure_rolls_back_identity_without_disclosing_exception_text(): void
    {
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into `mail_audits`')) {
                throw new \RuntimeException('SENSITIVE_SENTINEL');
            }
        });
        $this->prompt()->expectsConfirmation('Criar este primeiro master? O segundo fator continuará obrigatório', 'yes')
            ->doesntExpectOutputToContain('SENSITIVE_SENTINEL')->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('mail_audits', 0);
    }

    public function test_noninteractive_execution_never_prompts_or_creates_identity(): void
    {
        $this->artisan('brnmail:bootstrap-master', ['--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_second_connection_cannot_bootstrap_while_the_first_holds_the_lock(): void
    {
        config(['database.connections.bootstrap_contender' => config('database.connections.mysql')]);
        $other = DB::connection('bootstrap_contender');
        try {
            $this->assertSame(1, (int) $other->selectOne("SELECT GET_LOCK('brnmail.first-master', 0) AS acquired")->acquired);
            $this->artisan('brnmail:bootstrap-master')->assertFailed();
            $this->assertDatabaseCount('users', 0);
        } finally {
            $other->selectOne("SELECT RELEASE_LOCK('brnmail.first-master') AS released");
            DB::purge('bootstrap_contender');
        }
    }

    #[DataProvider('unsafeConfiguration')]
    public function test_unsafe_configuration_is_refused_without_opening_a_new_connection(string $key, mixed $value): void
    {
        $before = config($key);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        try {
            config([$key => $value]);
            $this->artisan('brnmail:bootstrap-master')->assertFailed();
            $this->assertSame(0, $queries);
        } finally {
            config([$key => $before]);
        }
    }

    public static function unsafeConfiguration(): array
    {
        return [
            ['brnmail.bootstrap_url', ''], ['brnmail.bootstrap_url', 'not-a-url'],
            ['brnmail.bootstrap_url', 'http://mail.example.com'],
            ['brnmail.bootstrap_url', 'https://other.example.com'],
            ['brnmail.bootstrap_url', 'https://user@mail.example.com'],
            ['brnmail.bootstrap_url', 'https://mail.example.com/setup'],
            ['brnmail.bootstrap_url', 'https://mail.example.com/?query=1'],
            ['brnmail.bootstrap_url', 'https://mail.example.com/#fragment'],
            ['app.debug', true], ['app.key', 'invalid'], ['app.url', 'http://mail.example.com'],
            ['brnmail.external_enabled', true], ['brnmail.local_demo', true], ['brnmail.transport', 'local'],
            ['database.default', 'sqlite'], ['database.connections.mysql.database', 'brnmail'],
            ['database.connections.mysql.host', 'example.test'], ['database.connections.mysql.port', '3306'],
            ['database.connections.mysql.url', 'mysql://example.test/other'],
            ['database.connections.mysql.unix_socket', '/tmp/other.sock'],
            ['database.connections.mysql.read', ['host' => 'other']],
        ];
    }
}
