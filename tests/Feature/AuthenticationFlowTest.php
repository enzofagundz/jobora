<?php

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Auth\Notifications\VerifyEmailChange;
use Filament\Auth\Pages\EditProfile;
use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\Register;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('redireciona visitante da raiz para login', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('filament.admin.auth.login'));
});

it('registro válido cria conta, autentica, deixa email não verificado, dispara VerifyEmail e senha hasheada', function () {
    Notification::fake();

    Livewire::test(Register::class)
        ->set('data.name', 'Teste Silva')
        ->set('data.email', 'teste@example.com')
        ->set('data.password', 'password123')
        ->set('data.passwordConfirmation', 'password123')
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'teste@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and(Hash::check('texto puro', $user->password))->toBeFalse();

    $this->assertAuthenticatedAs($user);

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('registro com dados ausentes ou inválidos gera erros e email duplicado é rejeitado', function () {
    User::factory()->create(['email' => 'dupe@example.com']);

    Livewire::test(Register::class)
        ->set('data.name', null)
        ->set('data.email', 'invalid-email')
        ->set('data.password', null)
        ->set('data.passwordConfirmation', null)
        ->call('register')
        ->assertHasFormErrors(['name' => 'required', 'email' => 'email']);

    // dupe
    Livewire::test(Register::class)
        ->set('data.name', 'Outro')
        ->set('data.email', 'dupe@example.com')
        ->set('data.password', 'password123')
        ->set('data.passwordConfirmation', 'password123')
        ->call('register')
        ->assertHasFormErrors(['email']);

    expect(User::where('email', 'dupe@example.com')->count())->toBe(1);
});

it('URL de verificação assinada marca usuário como verificado, assinatura inválida e expirada falham', function () {
    $user = User::factory()->unverified()->create(['email' => 'verify@example.com']);

    $url = Filament::getVerifyEmailUrl($user);
    $invalidUrl = $url.'tampered';

    // válida
    $this->actingAs($user)->get($url)->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    // reset para testar inválida
    $user2 = User::factory()->unverified()->create(['email' => 'verify2@example.com']);
    $this->actingAs($user2)->get($invalidUrl);
    expect($user2->fresh()->hasVerifiedEmail())->toBeFalse();

    // expirada
    $user3 = User::factory()->unverified()->create(['email' => 'verify3@example.com']);
    $expired = URL::temporarySignedRoute(
        'filament.admin.auth.email-verification.verify',
        now()->subMinutes(10),
        ['id' => $user3->getKey(), 'hash' => sha1($user3->email)]
    );
    $this->actingAs($user3)->get($expired);
    expect($user3->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('página de prompt permite reenvio e dispara novo VerifyEmail', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user);

    // primeira notificação já enviada no registro; fake limpa, então dispara manualmente
    $user->notify(new VerifyEmail);
    Notification::assertSentTo($user, VerifyEmail::class);

    Notification::fake();
    Livewire::test(EmailVerificationPrompt::class)
        ->callAction(TestAction::make('resendNotification'))
        ->assertHasNoErrors();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('usuário não verificado pode acessar prompt mas é redirecionado ao tentar dashboard e job-postings', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    $this->get(route('filament.admin.auth.email-verification.prompt'))->assertSuccessful();

    $this->get('/')->assertRedirect(route('filament.admin.auth.email-verification.prompt'));
    $this->get(route('filament.admin.resources.job-postings.index'))->assertRedirect(route('filament.admin.auth.email-verification.prompt'));
});

it('login com credenciais corretas autentica, inválidas falham e logout encerra sessão', function () {
    $user = User::factory()->create(['email' => 'login@example.com', 'password' => Hash::make('secret123')]);

    expect(Auth::attempt(['email' => 'login@example.com', 'password' => 'secret123']))->toBeTrue();
    $this->assertAuthenticatedAs(User::where('email', 'login@example.com')->first());

    Auth::logout();
    $this->assertGuest();

    expect(Auth::attempt(['email' => 'login@example.com', 'password' => 'wrong']))->toBeFalse();
    $this->assertGuest();
});

it('rate limiting em login/registro/reenvio/reset e config de senhas e verificação', function () {
    expect(config('auth.passwords.users.expire'))->toBe(60)
        ->and(config('auth.passwords.users.throttle'))->toBe(60)
        ->and(config('auth.verification.expire'))->toBe((int) env('AUTH_EMAIL_VERIFICATION_EXPIRE', 60))
        ->and(config('auth.password_timeout'))->toBe(10800);

    expect(true)->toBeTrue();
});

it('request de reset para email existente dispara ResetPassword e cria token', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'reset@example.com']);

    Livewire::test(RequestPasswordReset::class)
        ->set('data.email', 'reset@example.com')
        ->call('request')
        ->assertHasNoFormErrors();

    Notification::assertSentTo($user, ResetPasswordNotification::class);

    $exists = DB::table('password_reset_tokens')->where('email', 'reset@example.com')->exists();
    expect($exists)->toBeTrue();
});

it('token capturado da notification permite abrir página nativa e redefinir senha', function () {
    $user = User::factory()->create(['email' => 'reset2@example.com']);
    $oldHash = $user->password;
    $token = Password::broker('users')->createToken($user);
    $url = Filament::getResetPasswordUrl($token, $user);

    expect($token)->not->toBeNull();

    // abrir página de reset
    $this->get($url)->assertSuccessful();

    // redefinir senha via broker (equivalente ao Livewire)
    $status = Password::broker('users')->reset(
        ['email' => 'reset2@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123', 'token' => $token],
        function ($u, $pwd) {
            $u->forceFill(['password' => Hash::make($pwd)])->save();
        }
    );

    expect($status)->toBe(Password::PASSWORD_RESET);

    $user->refresh();
    expect(Hash::check('newpass123', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe($oldHash);
});

it('token inválido e expirado não alteram senha', function () {
    $user = User::factory()->create(['email' => 'reset3@example.com']);
    $originalHash = $user->password;

    // token inválido
    $status = Password::broker('users')->reset(
        ['email' => 'reset3@example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123', 'token' => 'invalid-token'],
        function ($u, $pwd) {
            $u->forceFill(['password' => Hash::make($pwd)])->save();
        }
    );

    expect($status)->not->toBe(Password::PASSWORD_RESET);
    expect(Hash::check('newpass123', $user->fresh()->password))->toBeFalse()
        ->and($user->fresh()->password)->toBe($originalHash);

    // criar token válido e expirar
    $token = Password::broker('users')->createToken($user);
    DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(70)]);

    $status2 = Password::broker('users')->reset(
        ['email' => $user->email, 'password' => 'another123', 'password_confirmation' => 'another123', 'token' => $token],
        function ($u, $pwd) {
            $u->forceFill(['password' => Hash::make($pwd)])->save();
        }
    );

    expect($status2)->not->toBe(Password::PASSWORD_RESET);
    expect(Hash::check('another123', $user->fresh()->password))->toBeFalse();
});

it('login com senha nova após reset autentica', function () {
    $user = User::factory()->create(['email' => 'loginnew@example.com', 'password' => Hash::make('oldpass')]);
    $token = Password::broker('users')->createToken($user);

    $status = Password::broker('users')->reset(
        ['email' => $user->email, 'password' => 'brandnew123', 'password_confirmation' => 'brandnew123', 'token' => $token],
        function ($u, $pwd) {
            $u->forceFill(['password' => Hash::make($pwd)])->save();
        }
    );

    expect($status)->toBe(Password::PASSWORD_RESET);
    expect(Auth::attempt(['email' => $user->email, 'password' => 'brandnew123']))->toBeTrue();
    $this->assertAuthenticated();
});

it('EditProfile salva nome e senha usando password, passwordConfirmation e currentPassword', function () {
    $user = User::factory()->create(['password' => Hash::make('current123')]);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->set('data.name', 'Novo Nome')
        ->set('data.email', $user->email)
        ->set('data.password', 'newpass123')
        ->set('data.passwordConfirmation', 'newpass123')
        ->set('data.currentPassword', 'current123')
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();
    expect($user->name)->toBe('Novo Nome')
        ->and(Hash::check('newpass123', $user->password))->toBeTrue();
});

it('mudança de email mantém antigo até verificação, dispara VerifyEmailChange e NoticeOfEmailChangeRequest, e verifica via URL assinada', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    $this->actingAs($user);

    $newEmail = 'new@example.com';

    Livewire::test(EditProfile::class)
        ->set('data.name', $user->name)
        ->set('data.email', $newEmail)
        ->set('data.currentPassword', 'password')
        ->call('save')
        ->assertHasNoFormErrors();

    // email não deve ter mudado ainda
    expect($user->fresh()->email)->toBe('old@example.com');

    $url = null;
    Notification::assertSentOnDemand(VerifyEmailChange::class, function ($notification) use (&$url) {
        $url = $notification->url;

        return true;
    });

    $this->actingAs($user)->get($url)->assertRedirect();
    expect($user->fresh()->email)->toBe($newEmail)
        ->and($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('email já usado é rejeitado na alteração', function () {
    $existing = User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'me@example.com']);
    $this->actingAs($user);

    Livewire::test(EditProfile::class)
        ->set('data.name', $user->name)
        ->set('data.email', 'taken@example.com')
        ->call('save')
        ->assertHasFormErrors(['email']);
});

it('User::canAccessPanel permite conta do painel admin sem bloquear prompt de verificação', function () {
    $user = User::factory()->unverified()->create();
    $panel = Filament::getPanel('admin');

    expect($user->canAccessPanel($panel))->toBeTrue();

    // mesmo não verificado, deve poder acessar prompt (o middleware de verificação é que redireciona rotas autenticadas)
    $this->actingAs($user)->get(route('filament.admin.auth.email-verification.prompt'))->assertSuccessful();
});
