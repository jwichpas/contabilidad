<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Security\AuditLoggerService;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    // Fixtures exclusivos de cada test; Pest.php revierte la transacción.
    // No reutilizar ni modificar cuentas o empresas existentes.
    $this->company = Company::query()->create([
        'tax_id' => '20'.(string) random_int(100000000, 999999999),
        'business_name' => 'Empresa de prueba de seguridad',
        'legal_address' => 'Dirección de prueba',
        'is_active' => DB::raw('TRUE'),
    ]);

    $this->user = User::query()->create([
        'email' => 'security-'.bin2hex(random_bytes(8)).'@example.test',
        'name' => 'Usuario de prueba de seguridad',
        'password' => 'Contabilidad2026!',
        'current_company_id' => null,
    ]);

    app(TenantContext::class)->run($this->company->id, function (): void {
        DB::table('company_user')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'role' => 'ACCOUNTANT',
            'is_active' => DB::raw('TRUE'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // La FK compuesta exige crear la membresía antes de asignar empresa.
        $this->user->update(['current_company_id' => $this->company->id]);
    });
});

describe('ISO/IEC 27001:2023 Security & SGSI Controls', function (): void {
    test('Control 8.24 & 8.26: Inyecta encabezados de seguridad HTTP estrictos y previene caché en endpoints API', function (): void {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'Contabilidad2026!',
        ]);

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->toContain('no-cache')
            ->toContain('must-revalidate');
        $response->assertHeader('Pragma', 'no-cache');
    });

    test('Control 8.15: Registra en audit_logs los eventos de inicio de sesión exitoso y fallido con severidad adecuada', function (): void {
        $failedWrites = [];
        // RLS impide leer eventos globales (company_id NULL). Observar el
        // INSERT ejecutado, sin relajar políticas ni usar un rol privilegiado.
        DB::listen(function (QueryExecuted $query) use (&$failedWrites): void {
            if (! preg_match('/^insert into "audit_logs"\s*\(([^)]+)\)/i', $query->sql, $matches)) {
                return;
            }

            $columns = array_map(static fn (string $column): string => trim($column, ' "'), explode(',', $matches[1]));
            if (count($columns) !== count($query->bindings)) {
                return;
            }

            $values = array_combine($columns, $query->bindings);
            if (($values['event_type'] ?? null) === 'AUTH_LOGIN_FAILED') {
                $failedWrites[] = $values + ['executed_sql' => $query->sql];
            }
        });

        // Intento fallido
        $failedResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'PasswordInvalido123!',
        ]);

        $failedResponse->assertStatus(422);

        expect($failedWrites)->toHaveCount(1);
        $failedLog = $failedWrites[0];
        expect($failedLog['severity'])->toBe('WARNING')
            ->and((int) $failedLog['user_id'])->toBe($this->user->id)
            ->and($failedLog['company_id'])->toBeNull()
            ->and(strtolower($failedLog['executed_sql']))->not->toContain('returning')
            ->and(json_decode($failedLog['new_values'], true, 512, JSON_THROW_ON_ERROR)['attempted_email'])
            ->toBe($this->user->email);

        // Intento exitoso
        $successResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'Contabilidad2026!',
        ]);

        $successResponse->assertOk();

        $successLog = app(TenantContext::class)->run($this->company->id, function (): ?AuditLog {
            return AuditLog::query()
                ->where('company_id', $this->company->id)
                ->where('user_id', $this->user->id)
                ->where('event_type', 'AUTH_LOGIN')
                ->latest('id')
                ->first();
        });

        expect($successLog)->not->toBeNull();
        expect($successLog->severity)->toBe('INFO');
        expect($successLog->user_id)->toBe($this->user->id);
    });

    test('Control 8.11: Enmascara datos confidenciales como contraseñas y tokens antes de almacenar en logs', function (): void {
        $service = app(AuditLoggerService::class);

        $sensitivePayload = [
            'user' => 'admin',
            'password' => 'SuperSecret123!',
            'plain_text_token' => '1|928374982374982374',
            'nested' => [
                'bank_account_number' => '191-12345678-0-99',
                'api_key' => 'sk_live_secret_key_value',
                'regular_field' => 'visible_information',
            ],
        ];

        $masked = $service->maskSensitiveData($sensitivePayload);

        expect($masked['password'])->toBe('***MASKED_CONFIDENTIAL***');
        expect($masked['plain_text_token'])->toBe('***MASKED_CONFIDENTIAL***');
        expect($masked['user'])->toBe('admin');
        expect($masked['nested']['bank_account_number'])->toBe('***MASKED_CONFIDENTIAL***');
        expect($masked['nested']['api_key'])->toBe('***MASKED_CONFIDENTIAL***');
        expect($masked['nested']['regular_field'])->toBe('visible_information');
    });

    test('Control 8.5: Limita la tasa de peticiones (Rate Limiting) para mitigar ataques de fuerza bruta en login', function (): void {
        // Enviar 10 intentos con contraseñas erróneas
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => $this->user->email,
                'password' => 'WrongPass_' . $i,
            ]);
            $response->assertStatus(422);
        }

        // El intento 11 debe ser bloqueado por HTTP 429 Too Many Requests
        $throttledResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'WrongPass_11',
        ]);

        $throttledResponse->assertStatus(429);
    });
});
