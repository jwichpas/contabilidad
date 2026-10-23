<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\AuditLog;
use App\Models\Entity;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * F3.3: directorio de anexos. Las escrituras se autorizan en routes/api.php,
 * mientras SetTenantContext aplica membresía/RLS a lecturas y escrituras.
 * La auditoría es obligatoria: error al insertarla revierte la operación.
 */
final readonly class EntityMasterController
{
    private const FIELDS = [
        'identity_document_type_code', 'tax_id', 'legal_name', 'commercial_name',
        'fiscal_address', 'country_code', 'email', 'phone',
        'is_client', 'is_supplier', 'is_employee', 'is_financial_institution',
        'is_shareholder', 'is_tax_authority', 'is_related_party',
        'is_retention_agent', 'is_good_taxpayer',
    ];

    private const ROLES = [
        'is_client', 'is_supplier', 'is_employee', 'is_financial_institution',
        'is_shareholder', 'is_tax_authority',
    ];

    /** Safe projection: bank accounts, CCI and detraction numbers are never returned. */
    public const PUBLIC_FIELDS = [
        'id', 'uuid', 'company_id', 'identity_document_type_code', 'tax_id',
        'legal_name', 'commercial_name', 'fiscal_address', 'country_code',
        'email', 'phone', 'is_client', 'is_supplier', 'is_employee',
        'is_financial_institution', 'is_shareholder', 'is_tax_authority',
        'is_related_party', 'is_retention_agent', 'is_good_taxpayer', 'is_active',
        'created_at', 'updated_at',
    ];

    public function __construct(private TenantContext $tenantContext) {}

    public function show(int $entityId): JsonResponse
    {
        $entity = Entity::query()->select(self::PUBLIC_FIELDS)->findOrFail($entityId);
        return response()->json(['data' => $entity]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->tenantContext->requireCompanyId(Entity::class);
        $validated = $this->validated($request, null, $companyId);
        $validated['country_code'] ??= 'PER';

        $entity = $this->writeWithUniqueGuard(function () use ($validated, $request, $companyId): Entity {
            return DB::transaction(function () use ($validated, $request, $companyId): Entity {
                $entity = Entity::query()->create($validated);
                $this->audit('ENTITY_CREATED', $entity, null, $request, $companyId);
                return $entity;
            });
        });

        return response()->json([
            'message' => 'Anexo creado y auditado.',
            'data' => $this->publicEntity($entity),
        ], 201);
    }

    public function update(Request $request, int $entityId): JsonResponse
    {
        $companyId = $this->tenantContext->requireCompanyId(Entity::class);
        $entity = Entity::query()->findOrFail($entityId);
        if (! $entity->is_active) {
            throw ValidationException::withMessages(['entity' => ['No se puede editar un anexo desactivado.']]);
        }
        $validated = $this->validated($request, $entity, $companyId);

        $entity = $this->writeWithUniqueGuard(function () use ($validated, $request, $companyId, $entity): Entity {
            return DB::transaction(function () use ($validated, $request, $companyId, $entity): Entity {
                // Lock to prevent two simultaneous updates from losing changes.
                $locked = Entity::query()->lockForUpdate()->findOrFail($entity->id);
                if (! $locked->is_active) {
                    throw ValidationException::withMessages(['entity' => ['El anexo fue desactivado.']]);
                }
                $old = $this->snapshot($locked);
                $locked->fill($validated);
                $locked->save();
                if ($old !== $this->snapshot($locked)) {
                    $this->audit('ENTITY_UPDATED', $locked, $old, $request, $companyId);
                }
                return $locked;
            });
        });

        return response()->json([
            'message' => 'Anexo actualizado.',
            'data' => $this->publicEntity($entity),
        ]);
    }

    public function deactivate(Request $request, int $entityId): JsonResponse
    {
        $companyId = $this->tenantContext->requireCompanyId(Entity::class);
        $entity = DB::transaction(function () use ($entityId, $request, $companyId): Entity {
            $entity = Entity::query()->lockForUpdate()->findOrFail($entityId);
            if ($entity->is_active) {
                $old = $this->snapshot($entity);
                $entity->is_active = false;
                $entity->save();
                $this->audit('ENTITY_DEACTIVATED', $entity, $old, $request, $companyId);
            }
            return $entity;
        });

        return response()->json([
            'message' => 'Anexo desactivado.',
            'data' => $this->publicEntity($entity),
        ]);
    }

    private function validated(Request $request, ?Entity $entity, int $companyId): array
    {
        // Never allow client-controlled tenant or direct banking attributes.
        $rules = [
            'company_id' => ['prohibited'], 'uuid' => ['prohibited'],
            'is_active' => ['prohibited'], 'id' => ['prohibited'],
            'bank_code' => ['prohibited'], 'bank_account_number' => ['prohibited'],
            'bank_cci_number' => ['prohibited'], 'detraction_account_number' => ['prohibited'],
            'has_detraction_account' => ['prohibited'],
            'identity_document_type_code' => [
                $entity ? 'sometimes' : 'required', 'required', 'string',
                Rule::exists('sunat_table_02_identity_document_types', 'code'),
            ],
            'tax_id' => [$entity ? 'sometimes' : 'required', 'required', 'string', 'max:20'],
            'legal_name' => [$entity ? 'sometimes' : 'required', 'required', 'string', 'max:200'],
            'commercial_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'fiscal_address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'country_code' => ['sometimes', 'required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/D'],
            'email' => ['sometimes', 'nullable', 'email', 'max:200'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
        foreach (array_merge(self::ROLES, ['is_related_party','is_retention_agent','is_good_taxpayer']) as $field) {
            $rules[$field] = ['sometimes', 'boolean'];
        }
        $document = (string) $request->input('identity_document_type_code',
            $entity?->identity_document_type_code ?? '');
        $rules['tax_id'][] = Rule::unique('entities', 'tax_id')
            ->where('company_id', $companyId)
            ->where('identity_document_type_code', $document)
            ->ignore($entity?->id);

        $data = $request->validate($rules);
        $effectiveDocument = (string) ($data['identity_document_type_code']
            ?? $entity?->identity_document_type_code ?? '');
        $taxId = trim((string) ($data['tax_id'] ?? $entity?->tax_id ?? ''));
        if (($effectiveDocument === '6' && preg_match('/^[0-9]{11}$/D', $taxId) !== 1)
            || ($effectiveDocument === '1' && preg_match('/^[0-9]{8}$/D', $taxId) !== 1)
            || $taxId === '') {
            throw ValidationException::withMessages([
                'tax_id' => ['Documento inválido: DNI requiere 8 dígitos y RUC 11 dígitos.'],
            ]);
        }
        $data['tax_id'] = $taxId;

        $roles = [];
        foreach (self::ROLES as $field) {
            $roles[] = (bool)($data[$field] ?? $entity?->getAttribute($field) ?? false);
        }
        if (! in_array(true, $roles, true)) {
            throw ValidationException::withMessages([
                'roles' => ['Seleccione al menos una clasificación: cliente, proveedor, trabajador u otra.'],
            ]);
        }

        return array_intersect_key($data, array_flip(self::FIELDS));
    }

    private function publicEntity(Entity $entity): array
    {
        return $entity->only(self::PUBLIC_FIELDS);
    }

    /** Audit only business fields, never banking credentials or full document IDs. */
    private function snapshot(Entity $entity): array
    {
        $snapshot = $entity->only(self::FIELDS);
        $id = (string)($snapshot['tax_id'] ?? '');
        $snapshot['tax_id'] = $id === '' ? '' : str_repeat('*', max(strlen($id) - 4, 0)).substr($id, -4);
        $snapshot['is_active'] = (bool)$entity->is_active;
        return $snapshot;
    }

    private function audit(
        string $event, Entity $entity, ?array $old, Request $request, int $companyId
    ): void {
        // Deliberately does NOT call the best-effort AuditLoggerService:
        // this master-data workflow must fail closed on any missing audit.
        AuditLog::query()->create([
            'company_id' => $companyId,
            'user_id' => $request->user()?->id,
            'event_type' => $event,
            'auditable_type' => Entity::class,
            'auditable_id' => (string)$entity->id,
            'old_values' => $old,
            'new_values' => $this->snapshot($entity),
            'ip_address' => substr((string)$request->ip(), 0, 45),
            'user_agent' => substr((string)$request->userAgent(), 0, 500),
            'severity' => 'INFO',
        ]);
    }

    private function writeWithUniqueGuard(callable $action): Entity
    {
        try {
            return $action();
        } catch (QueryException $e) {
            // Nested transaction/savepoint already rolled back before returning 422.
            if (($e->errorInfo[0] ?? '') === '23505') {
                throw ValidationException::withMessages([
                    'tax_id' => ['Ya existe un anexo con ese tipo y número de documento en esta empresa.'],
                ]);
            }
            throw $e;
        }
    }
}
