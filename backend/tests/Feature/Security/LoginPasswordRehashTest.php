<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    config(['hashing.driver' => 'bcrypt', 'hashing.bcrypt.rounds' => 6]);
    Hash::forgetDrivers();

    $this->password = 'Synthetic-login-password-2026!';
    $this->loginUser = User::query()->create([
        'name' => 'Login rehash fixture',
        'email' => 'rehash-'.bin2hex(random_bytes(8)).'@example.test',
        'password' => Hash::make($this->password, ['rounds' => 4]),
        'current_company_id' => null,
    ]);
});

it('upgrades an outdated hash only after a correct login', function (): void {
    $previousHash = $this->loginUser->password;

    $this->postJson('/api/v1/auth/login', [
        'email' => $this->loginUser->email,
        'password' => $this->password,
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);

    $currentHash = $this->loginUser->refresh()->password;
    expect($currentHash)->not->toBe($previousHash)
        ->and(Hash::check($this->password, $currentHash))->toBeTrue()
        ->and(Hash::needsRehash($currentHash))->toBeFalse();
});

it('does not rewrite a hash that already meets the configured cost', function (): void {
    $this->loginUser->password = Hash::make($this->password);
    $this->loginUser->save();
    $previousHash = $this->loginUser->password;

    $this->postJson('/api/v1/auth/login', [
        'email' => $this->loginUser->email,
        'password' => $this->password,
    ])->assertOk();

    expect($this->loginUser->refresh()->password)->toBe($previousHash);
});

it('does not change the hash or issue a token for an incorrect password', function (): void {
    $previousHash = $this->loginUser->password;

    $this->postJson('/api/v1/auth/login', [
        'email' => $this->loginUser->email,
        'password' => 'Incorrect-synthetic-password!',
    ])->assertUnprocessable();

    expect($this->loginUser->refresh()->password)->toBe($previousHash)
        ->and($this->loginUser->tokens()->count())->toBe(0);
});

it('does not overwrite a password changed concurrently during login', function (): void {
    $userId = $this->loginUser->id;
    $replacementHash = Hash::make('Replacement-synthetic-password!');
    $changed = false;

    // Change the dummy account after password verification, while login
    // loads memberships and before it attempts the conditional rehash.
    DB::listen(function (QueryExecuted $query) use ($userId, $replacementHash, &$changed): void {
        if (! $changed && str_contains($query->sql, '"company_user"') && str_contains($query->sql, '"companies"')) {
            $changed = true;
            DB::table('users')->where('id', $userId)->update(['password' => $replacementHash]);
        }
    });

    $this->postJson('/api/v1/auth/login', [
        'email' => $this->loginUser->email,
        'password' => $this->password,
    ])->assertUnprocessable();

    expect($changed)->toBeTrue()
        ->and($this->loginUser->refresh()->password)->toBe($replacementHash)
        ->and($this->loginUser->tokens()->count())->toBe(0);
});
