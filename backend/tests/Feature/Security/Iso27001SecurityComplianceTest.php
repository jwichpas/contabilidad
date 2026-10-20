<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Security\AuditLoggerService;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear(sha1('throttle:10,1'));

    $this->company = Company::query()->firstOrCreate(
        ['tax_id' => '20601234567'],
        [
            'business_name' => 'Corporación Peruana de Distribución S.A.C.',
            'legal_address' => 'Av. Javier Prado Este 2450, San Borja, Lima',
            'is_active' => true,
        ]
    );

    $this->user = User::query()->firstOrCreate(
        ['email' => 'contador@corporacionperu.pe'],
        [
            'name' => 'Contador General CPC',
            'password' => 'Contabilidad2026!',
            'current_company_id' => $this->company->id,
        ]
    );
});

describe('ISO/IEC 27001:2023 Security & SGSI Controls', function (): void {
    test('Control 8.24 & 8.26: Inyecta encabezados de seguridad HTTP estrictos y previene caché en endpoints API', function (): void {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'contador@corporacionperu.pe',
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
        // Intento fallido
        $failedResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'contador@corporacionperu.pe',
            'password' => 'PasswordInvalido123!',
        ]);

        $failedResponse->assertStatus(422);

        $failedLog = AuditLog::query()
            ->where('event_type', 'AUTH_LOGIN_FAILED')
            ->latest('id')
            ->first();

        expect($failedLog)->not->toBeNull();
        expect($failedLog->severity)->toBe('WARNING');
        expect($failedLog->new_values['attempted_email'] ?? null)->toBe('contador@corporacionperu.pe');

        // Intento exitoso
        $successResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'contador@corporacionperu.pe',
            'password' => 'Contabilidad2026!',
        ]);

        $successResponse->assertOk();

        $successLog = AuditLog::query()
            ->where('event_type', 'AUTH_LOGIN')
            ->latest('id')
            ->first();

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
                'email' => 'contador@corporacionperu.pe',
                'password' => 'WrongPass_' . $i,
            ]);
            $response->assertStatus(422);
        }

        // El intento 11 debe ser bloqueado por HTTP 429 Too Many Requests
        $throttledResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'contador@corporacionperu.pe',
            'password' => 'WrongPass_11',
        ]);

        $throttledResponse->assertStatus(429);
    });
});
