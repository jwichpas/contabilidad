<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Sunat\SunatCustomsDependency;
use App\Models\Sunat\SunatElectronicBook;
use App\Models\Sunat\SunatElectronicDocumentType;
use App\Models\Sunat\SunatFinancialInstitution;
use App\Models\Sunat\SunatIdentityDocumentType;
use App\Models\Sunat\SunatInventoryType;
use App\Models\Sunat\SunatMeasurementUnit;
use App\Models\Sunat\SunatOperationType;
use App\Models\Sunat\SunatPaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Seeder que puebla los Catálogos Oficiales de SUNAT para Libros Electrónicos (PLE / SIRE)
 * basados exactamente en el anexo de tablas-sunat.md.
 */
class SunatCatalogSeeder extends Seeder
{
    private bool $bulkGlobalInstall = false;

    /** Only the F2 guarded installer may request insert-only, batched loading. */
    public function runForGlobalInstall(): void
    {
        if ($this->bulkGlobalInstall) {
            throw new \RuntimeException('Carga global ya iniciada.');
        }

        $this->bulkGlobalInstall = true;
        try {
            $this->run();
        } finally {
            $this->bulkGlobalInstall = false;
        }
    }

    public function run(): void
    {
        $this->seedTable01PaymentMethods();
        $this->seedTable02IdentityDocumentTypes();
        $this->seedTable03FinancialInstitutions();
        $this->seedTable05InventoryTypes();
        $this->seedTable06MeasurementUnits();
        $this->seedTable08ElectronicBooks();
        $this->seedTable10ElectronicDocumentTypes();
        $this->seedTable11CustomsDependencies();
        $this->seedTable12OperationTypes();
    }

    private function seedTable01PaymentMethods(): void
    {
        $items = [
            ['code' => '001', 'name' => 'DEPÓSITO EN CUENTA', 'requires_financial_entity' => true, 'requires_operation_number' => true],
            ['code' => '002', 'name' => 'GIRO', 'requires_financial_entity' => true, 'requires_operation_number' => true],
            ['code' => '003', 'name' => 'TRANSFERENCIA DE FONDOS', 'requires_financial_entity' => true, 'requires_operation_number' => true],
            ['code' => '004', 'name' => 'ORDEN DE PAGO', 'requires_financial_entity' => true, 'requires_operation_number' => true],
            ['code' => '005', 'name' => 'TARJETA DE DÉBITO', 'requires_financial_entity' => true, 'requires_operation_number' => true],
            ['code' => '006', 'name' => 'TARJETA DE CRÉDITO', 'requires_financial_entity' => true, 'requires_operation_number' => true],
            ['code' => '007', 'name' => 'CHEQUES CON LA CLÁUSULA DE "NO NEGOCIABLE", "INTRANSFERIBLES", "NO A LA ORDEN"', 'requires_financial_entity' => true, 'requires_operation_number' => true],
            ['code' => '008', 'name' => 'EFECTIVO, POR OPERACIONES EN LAS QUE NO EXISTE OBLIGACIÓN DE UTILIZAR MEDIOS DE PAGO', 'requires_financial_entity' => false, 'requires_operation_number' => false],
            ['code' => '009', 'name' => 'EFECTIVO, EN LOS DEMÁS CASOS', 'requires_financial_entity' => false, 'requires_operation_number' => false],
            ['code' => '010', 'name' => 'MEDIOS DE PAGO DE COMERCIO EXTERIOR', 'requires_financial_entity' => true, 'requires_operation_number' => true, 'is_foreign_trade' => true],
            ['code' => '011', 'name' => 'LETRAS DE CAMBIO', 'requires_financial_entity' => false, 'requires_operation_number' => true],
            ['code' => '101', 'name' => 'TRANSFERENCIAS - COMERCIO EXTERIOR', 'requires_financial_entity' => true, 'requires_operation_number' => true, 'is_foreign_trade' => true],
            ['code' => '102', 'name' => 'CHEQUES BANCARIOS - COMERCIO EXTERIOR', 'requires_financial_entity' => true, 'requires_operation_number' => true, 'is_foreign_trade' => true],
            ['code' => '103', 'name' => 'ORDEN DE PAGO SIMPLE - COMERCIO EXTERIOR', 'requires_financial_entity' => true, 'requires_operation_number' => true, 'is_foreign_trade' => true],
            ['code' => '104', 'name' => 'ORDEN DE PAGO DOCUMENTARIO - COMERCIO EXTERIOR', 'requires_financial_entity' => true, 'requires_operation_number' => true, 'is_foreign_trade' => true],
            ['code' => '105', 'name' => 'REMESA SIMPLE - COMERCIO EXTERIOR', 'requires_financial_entity' => true, 'requires_operation_number' => true, 'is_foreign_trade' => true],
            ['code' => '106', 'name' => 'REMESA DOCUMENTARIA - COMERCIO EXTERIOR', 'requires_financial_entity' => true, 'requires_operation_number' => true, 'is_foreign_trade' => true],
            ['code' => '107', 'name' => 'CARTA DE CRÉDITO SIMPLE - COMERCIO EXTERIOR', 'requires_financial_entity' => true, 'requires_operation_number' => true, 'is_foreign_trade' => true],
            ['code' => '108', 'name' => 'CARTA DE CRÉDITO DOCUMENTARIO - COMERCIO EXTERIOR', 'requires_financial_entity' => true, 'requires_operation_number' => true, 'is_foreign_trade' => true],
            ['code' => '999', 'name' => 'OTROS MEDIOS DE PAGO (ESPECIFICAR)', 'requires_financial_entity' => false, 'requires_operation_number' => false],
        ];

        if ($this->bulkGlobalInstall) {
            \App\Support\Reference\GlobalReferenceBatchWriter::insert(SunatPaymentMethod::class, $items);
            return;
        }

        foreach ($items as $item) {
            SunatPaymentMethod::query()->updateOrCreate(['code' => $item['code']], $item);
        }
    }

    private function seedTable02IdentityDocumentTypes(): void
    {
        $items = [
            ['code' => '0', 'name' => 'OTROS TIPOS DE DOCUMENTOS', 'abbreviation' => 'OTROS', 'exact_length' => null, 'max_length' => 15, 'is_numeric_only' => false],
            ['code' => '1', 'name' => 'DOCUMENTO NACIONAL DE IDENTIDAD (DNI)', 'abbreviation' => 'DNI', 'exact_length' => 8, 'max_length' => 8, 'is_numeric_only' => true],
            ['code' => '4', 'name' => 'CARNET DE EXTRANJERÍA', 'abbreviation' => 'C.E.', 'exact_length' => null, 'max_length' => 12, 'is_numeric_only' => false],
            ['code' => '6', 'name' => 'REGISTRO ÚNICO DE CONTRIBUYENTES (RUC)', 'abbreviation' => 'RUC', 'exact_length' => 11, 'max_length' => 11, 'is_numeric_only' => true],
            ['code' => '7', 'name' => 'PASAPORTE', 'abbreviation' => 'PASAPORTE', 'exact_length' => null, 'max_length' => 12, 'is_numeric_only' => false],
        ];

        if ($this->bulkGlobalInstall) {
            \App\Support\Reference\GlobalReferenceBatchWriter::insert(SunatIdentityDocumentType::class, $items);
            return;
        }

        foreach ($items as $item) {
            SunatIdentityDocumentType::query()->updateOrCreate(['code' => $item['code']], $item);
        }
    }

    private function seedTable03FinancialInstitutions(): void
    {
        $items = [
            ['code' => '01', 'name' => 'CENTRAL RESERVA DEL PERU', 'short_name' => 'BCRP', 'tax_id' => '20130554474'],
            ['code' => '02', 'name' => 'BANCO DE CREDITO DEL PERU', 'short_name' => 'BCP', 'tax_id' => '20100047218'],
            ['code' => '03', 'name' => 'BANCO INTERNACIONAL DEL PERU', 'short_name' => 'INTERBANK', 'tax_id' => '20100053455'],
            ['code' => '05', 'name' => 'BANCO LATINO', 'short_name' => 'LATINO', 'tax_id' => null],
            ['code' => '07', 'name' => 'CITIBANK DEL PERU S.A.', 'short_name' => 'CITIBANK', 'tax_id' => '20259388368'],
            ['code' => '08', 'name' => 'STANDARD CHARTERED', 'short_name' => 'STANDARD', 'tax_id' => null],
            ['code' => '09', 'name' => 'SCOTIABANK PERU', 'short_name' => 'SCOTIABANK', 'tax_id' => '20100105617'],
            ['code' => '11', 'name' => 'BANCO CONTINENTAL', 'short_name' => 'BBVA', 'tax_id' => '20100130204'],
            ['code' => '12', 'name' => 'BANCO DE LIMA', 'short_name' => 'DE LIMA', 'tax_id' => null],
            ['code' => '16', 'name' => 'MERCANTIL', 'short_name' => 'MERCANTIL', 'tax_id' => null],
            ['code' => '18', 'name' => 'BANCO DE LA NACION', 'short_name' => 'BANCO NACION', 'tax_id' => '20100030595'],
            ['code' => '22', 'name' => 'SANTANDER CENTRAL HISPANO', 'short_name' => 'SANTANDER', 'tax_id' => '20309953332'],
            ['code' => '23', 'name' => 'BANCO DE COMERCIO', 'short_name' => 'BANCOMERCIO', 'tax_id' => '20504797037'],
            ['code' => '25', 'name' => 'BANCO REPUBLICA', 'short_name' => 'REPUBLICA', 'tax_id' => null],
            ['code' => '26', 'name' => 'NBK BANK', 'short_name' => 'NBK BANK', 'tax_id' => null],
            ['code' => '29', 'name' => 'BANCOSUR', 'short_name' => 'BANCOSUR', 'tax_id' => null],
            ['code' => '35', 'name' => 'BANCO FINANCIERO DEL PERU', 'short_name' => 'PICHINCHA', 'tax_id' => '20100105889'],
            ['code' => '37', 'name' => 'DEL PROGRESO', 'short_name' => 'PROGRESO', 'tax_id' => null],
            ['code' => '38', 'name' => 'BANCO INTERAMERICANO FINANZAS', 'short_name' => 'BANBIF', 'tax_id' => '20101034543'],
            ['code' => '39', 'name' => 'BANEX', 'short_name' => 'BANEX', 'tax_id' => null],
            ['code' => '40', 'name' => 'NUEVO MUNDO', 'short_name' => 'NUEVO MUNDO', 'tax_id' => null],
            ['code' => '41', 'name' => 'SUDAMERICANO', 'short_name' => 'SUDAMERICANO', 'tax_id' => null],
            ['code' => '42', 'name' => 'DEL LIBERTADOR', 'short_name' => 'LIBERTADOR', 'tax_id' => null],
            ['code' => '43', 'name' => 'DEL TRABAJO', 'short_name' => 'TRABAJO', 'tax_id' => null],
            ['code' => '44', 'name' => 'SOLVENTA', 'short_name' => 'SOLVENTA', 'tax_id' => null],
            ['code' => '45', 'name' => 'SERBANCO SA.', 'short_name' => 'SERBANCO', 'tax_id' => null],
            ['code' => '46', 'name' => 'BANK OF BOSTON', 'short_name' => 'BOSTON', 'tax_id' => null],
            ['code' => '47', 'name' => 'ORION', 'short_name' => 'ORION', 'tax_id' => null],
            ['code' => '48', 'name' => 'DEL PAIS', 'short_name' => 'PAIS', 'tax_id' => null],
            ['code' => '49', 'name' => 'MI BANCO', 'short_name' => 'MIBANCO', 'tax_id' => '20387530512'],
            ['code' => '50', 'name' => 'BNP PARIBAS', 'short_name' => 'BNP PARIBAS', 'tax_id' => '20508688402'],
            ['code' => '53', 'name' => 'HSBC BANK PERU S.A.', 'short_name' => 'HSBC', 'tax_id' => '20513904948'],
            ['code' => '99', 'name' => 'OTRAS ENTIDADES FINANCIERAS', 'short_name' => 'OTROS', 'tax_id' => null],
        ];

        if ($this->bulkGlobalInstall) {
            \App\Support\Reference\GlobalReferenceBatchWriter::insert(SunatFinancialInstitution::class, $items);
            return;
        }

        foreach ($items as $item) {
            SunatFinancialInstitution::query()->updateOrCreate(['code' => $item['code']], $item);
        }
    }

    private function seedTable05InventoryTypes(): void
    {
        $items = [
            ['code' => '01', 'name' => 'MERCADERÍA', 'related_account_element' => '20'],
            ['code' => '02', 'name' => 'PRODUCTO TERMINADO', 'related_account_element' => '21'],
            ['code' => '03', 'name' => 'MATERIAS PRIMAS Y AUXILIARES - MATERIALES', 'related_account_element' => '24'],
            ['code' => '04', 'name' => 'ENVASES Y EMBALAJES', 'related_account_element' => '26'],
            ['code' => '05', 'name' => 'SUMINISTROS DIVERSOS', 'related_account_element' => '25'],
            ['code' => '99', 'name' => 'OTROS (ESPECIFICAR)', 'related_account_element' => '29'],
        ];

        if ($this->bulkGlobalInstall) {
            \App\Support\Reference\GlobalReferenceBatchWriter::insert(SunatInventoryType::class, $items);
            return;
        }

        foreach ($items as $item) {
            SunatInventoryType::query()->updateOrCreate(['code' => $item['code']], $item);
        }
    }

    private function seedTable06MeasurementUnits(): void
    {
        $items = [
            ['code' => '01', 'name' => 'KILOGRAMOS', 'symbol' => 'KGM'],
            ['code' => '02', 'name' => 'LIBRAS', 'symbol' => 'LBR'],
            ['code' => '03', 'name' => 'TONELADAS LARGAS', 'symbol' => 'LTN'],
            ['code' => '04', 'name' => 'TONELADAS MÉTRICAS', 'symbol' => 'TNE'],
            ['code' => '05', 'name' => 'TONELADAS CORTAS', 'symbol' => 'STN'],
            ['code' => '06', 'name' => 'GRAMOS', 'symbol' => 'GRM'],
            ['code' => '07', 'name' => 'UNIDADES', 'symbol' => 'NIU'],
            ['code' => '08', 'name' => 'LITROS', 'symbol' => 'LTR'],
            ['code' => '09', 'name' => 'GALONES', 'symbol' => 'GLL'],
            ['code' => '10', 'name' => 'BARRILES', 'symbol' => 'BLL'],
            ['code' => '11', 'name' => 'LATAS', 'symbol' => 'CAN'],
            ['code' => '12', 'name' => 'CAJAS', 'symbol' => 'BX'],
            ['code' => '13', 'name' => 'MILLARES', 'symbol' => 'MLL'],
            ['code' => '14', 'name' => 'METROS CÚBICOS', 'symbol' => 'MTQ'],
            ['code' => '15', 'name' => 'METROS', 'symbol' => 'MTR'],
            ['code' => '99', 'name' => 'OTROS (ESPECIFICAR)', 'symbol' => 'ZZ'],
        ];

        if ($this->bulkGlobalInstall) {
            \App\Support\Reference\GlobalReferenceBatchWriter::insert(SunatMeasurementUnit::class, $items);
            return;
        }

        foreach ($items as $item) {
            SunatMeasurementUnit::query()->updateOrCreate(['code' => $item['code']], $item);
        }
    }

    private function seedTable08ElectronicBooks(): void
    {
        $items = [
            ['code' => '1', 'name' => 'LIBRO CAJA Y BANCOS', 'ple_book_code' => '010100', 'is_compulsory_general_regime' => true],
            ['code' => '2', 'name' => 'LIBRO DE INGRESOS Y GASTOS', 'ple_book_code' => '020100', 'is_compulsory_general_regime' => false],
            ['code' => '3', 'name' => 'LIBRO DE INVENTARIOS Y BALANCES', 'ple_book_code' => '030100', 'is_compulsory_general_regime' => true],
            ['code' => '4', 'name' => 'LIBRO DE RETENCIONES INCISOS E) Y F) ART. 34 LIR', 'ple_book_code' => '040100', 'is_compulsory_general_regime' => false],
            ['code' => '5', 'name' => 'LIBRO DIARIO', 'ple_book_code' => '050100', 'is_compulsory_general_regime' => true],
            ['code' => '6', 'name' => 'LIBRO MAYOR', 'ple_book_code' => '060100', 'is_compulsory_general_regime' => true],
            ['code' => '7', 'name' => 'REGISTRO DE ACTIVOS FIJOS', 'ple_book_code' => '070100', 'is_compulsory_general_regime' => true],
            ['code' => '8', 'name' => 'REGISTRO DE COMPRAS', 'ple_book_code' => '080100', 'is_compulsory_general_regime' => true],
            ['code' => '9', 'name' => 'REGISTRO DE CONSIGNACIONES', 'ple_book_code' => '090100', 'is_compulsory_general_regime' => false],
            ['code' => '10', 'name' => 'REGISTRO DE COSTOS', 'ple_book_code' => '100100', 'is_compulsory_general_regime' => true],
            ['code' => '11', 'name' => 'REGISTRO DE HUÉSPEDES', 'ple_book_code' => '110100', 'is_compulsory_general_regime' => false],
            ['code' => '12', 'name' => 'REGISTRO DE INVENTARIO PERMANENTE EN UNIDADES FÍSICAS', 'ple_book_code' => '120100', 'is_compulsory_general_regime' => false],
            ['code' => '13', 'name' => 'REGISTRO DE INVENTARIO PERMANENTE VALORIZADO', 'ple_book_code' => '130100', 'is_compulsory_general_regime' => true],
            ['code' => '14', 'name' => 'REGISTRO DE VENTAS E INGRESOS', 'ple_book_code' => '140100', 'is_compulsory_general_regime' => true],
            ['code' => '16', 'name' => 'REGISTRO DEL RÉGIMEN DE PERCEPCIONES', 'ple_book_code' => '160100', 'is_compulsory_general_regime' => false],
            ['code' => '17', 'name' => 'REGISTRO DEL RÉGIMEN DE RETENCIONES', 'ple_book_code' => '170100', 'is_compulsory_general_regime' => false],
            ['code' => '18', 'name' => 'REGISTRO IVAP', 'ple_book_code' => '180100', 'is_compulsory_general_regime' => false],
            ['code' => '28', 'name' => 'LIBRO DE ACTAS DE LA JUNTA GENERAL DE ACCIONISTAS', 'ple_book_code' => null, 'is_compulsory_general_regime' => true],
            ['code' => '30', 'name' => 'LIBRO DE MATRÍCULA DE ACCIONES', 'ple_book_code' => null, 'is_compulsory_general_regime' => true],
            ['code' => '31', 'name' => 'LIBRO DE PLANILLAS', 'ple_book_code' => null, 'is_compulsory_general_regime' => true],
        ];

        if ($this->bulkGlobalInstall) {
            \App\Support\Reference\GlobalReferenceBatchWriter::insert(SunatElectronicBook::class, $items);
            return;
        }

        foreach ($items as $item) {
            SunatElectronicBook::query()->updateOrCreate(['code' => $item['code']], $item);
        }
    }

    private function seedTable10ElectronicDocumentTypes(): void
    {
        $items = [
            ['code' => '00', 'name' => 'OTROS (ESPECIFICAR)', 'short_name' => 'OTROS', 'allows_tax_credit' => false],
            ['code' => '01', 'name' => 'FACTURA', 'short_name' => 'FT', 'allows_tax_credit' => true],
            ['code' => '02', 'name' => 'RECIBO POR HONORARIOS', 'short_name' => 'RXH', 'allows_tax_credit' => false],
            ['code' => '03', 'name' => 'BOLETA DE VENTA', 'short_name' => 'BV', 'allows_tax_credit' => false],
            ['code' => '04', 'name' => 'LIQUIDACIÓN DE COMPRA', 'short_name' => 'LC', 'allows_tax_credit' => true],
            ['code' => '05', 'name' => 'BOLETO DE TRANSPORTE AÉREO', 'short_name' => 'BTA', 'allows_tax_credit' => true],
            ['code' => '06', 'name' => 'CARTA DE PORTE AÉREO', 'short_name' => 'CPA', 'allows_tax_credit' => true],
            ['code' => '07', 'name' => 'NOTA DE CRÉDITO', 'short_name' => 'NC', 'allows_tax_credit' => true],
            ['code' => '08', 'name' => 'NOTA DE DÉBITO', 'short_name' => 'ND', 'allows_tax_credit' => true],
            ['code' => '09', 'name' => 'GUÍA DE REMISIÓN - REMITENTE', 'short_name' => 'GRR', 'allows_tax_credit' => false],
            ['code' => '10', 'name' => 'RECIBO POR ARRENDAMIENTO', 'short_name' => 'ARR', 'allows_tax_credit' => true],
            ['code' => '12', 'name' => 'TICKET O CINTA DE MÁQUINA REGISTRADORA', 'short_name' => 'TK', 'allows_tax_credit' => false],
            ['code' => '13', 'name' => 'DOCUMENTO EMITIDO POR BANCOS Y FINANCIERAS (SBS)', 'short_name' => 'DBCO', 'allows_tax_credit' => true],
            ['code' => '14', 'name' => 'RECIBO POR SERVICIOS PÚBLICOS', 'short_name' => 'SP', 'allows_tax_credit' => true],
            ['code' => '15', 'name' => 'BOLETO DE TRANSPORTE PÚBLICO URBANO', 'short_name' => 'BPU', 'allows_tax_credit' => false],
            ['code' => '16', 'name' => 'BOLETO DE TRANSPORTE INTERPROVINCIAL', 'short_name' => 'BIP', 'allows_tax_credit' => true],
            ['code' => '20', 'name' => 'COMPROBANTE DE RETENCIÓN', 'short_name' => 'CR', 'allows_tax_credit' => false],
            ['code' => '21', 'name' => 'CONOCIMIENTO DE EMBARQUE', 'short_name' => 'BL', 'allows_tax_credit' => true],
            ['code' => '31', 'name' => 'GUÍA DE REMISIÓN - TRANSPORTISTA', 'short_name' => 'GRT', 'allows_tax_credit' => false],
            ['code' => '50', 'name' => 'DECLARACIÓN ÚNICA DE ADUANAS (DUA/DAM)', 'short_name' => 'DUA', 'allows_tax_credit' => true],
            ['code' => '52', 'name' => 'DESPACHO SIMPLIFICADO - IMPORTACIÓN', 'short_name' => 'DSI', 'allows_tax_credit' => true],
            ['code' => '91', 'name' => 'COMPROBANTE DE NO DOMICILIADO', 'short_name' => 'CND', 'allows_tax_credit' => true],
            ['code' => '97', 'name' => 'NOTA DE CRÉDITO - NO DOMICILIADO', 'short_name' => 'NC-ND', 'allows_tax_credit' => true],
            ['code' => '98', 'name' => 'NOTA DE DÉBITO - NO DOMICILIADO', 'short_name' => 'ND-ND', 'allows_tax_credit' => true],
        ];

        if ($this->bulkGlobalInstall) {
            \App\Support\Reference\GlobalReferenceBatchWriter::insert(SunatElectronicDocumentType::class, $items);
            return;
        }

        foreach ($items as $item) {
            SunatElectronicDocumentType::query()->updateOrCreate(['code' => $item['code']], $item);
        }
    }

    private function seedTable11CustomsDependencies(): void
    {
        $items = [
            ['code' => '019', 'name' => 'TUMBES'],
            ['code' => '028', 'name' => 'TALARA'],
            ['code' => '046', 'name' => 'PAITA'],
            ['code' => '055', 'name' => 'CHICLAYO'],
            ['code' => '082', 'name' => 'SALAVERRY'],
            ['code' => '091', 'name' => 'CHIMBOTE'],
            ['code' => '118', 'name' => 'MARÍTIMA DEL CALLAO'],
            ['code' => '127', 'name' => 'PISCO'],
            ['code' => '145', 'name' => 'MOLLENDO MATARANI'],
            ['code' => '154', 'name' => 'AREQUIPA'],
            ['code' => '163', 'name' => 'ILO'],
            ['code' => '172', 'name' => 'TACNA'],
            ['code' => '181', 'name' => 'PUNO'],
            ['code' => '190', 'name' => 'CUZCO'],
            ['code' => '217', 'name' => 'PUCALLPA'],
            ['code' => '226', 'name' => 'IQUITOS'],
            ['code' => '235', 'name' => 'AÉREA DEL CALLAO'],
            ['code' => '244', 'name' => 'POSTAL DE LIMA'],
            ['code' => '262', 'name' => 'DESAGUADERO'],
            ['code' => '271', 'name' => 'TARAPOTO'],
            ['code' => '280', 'name' => 'PUERTO MALDONADO'],
            ['code' => '299', 'name' => 'LA TINA'],
        ];

        if ($this->bulkGlobalInstall) {
            \App\Support\Reference\GlobalReferenceBatchWriter::insert(SunatCustomsDependency::class, $items);
            return;
        }

        foreach ($items as $item) {
            SunatCustomsDependency::query()->updateOrCreate(['code' => $item['code']], $item);
        }
    }

    private function seedTable12OperationTypes(): void
    {
        $items = [
            ['code' => '01', 'name' => 'VENTA', 'operation_category' => 'VENTA'],
            ['code' => '02', 'name' => 'COMPRA', 'operation_category' => 'COMPRA'],
            ['code' => '03', 'name' => 'CONSIGNACIÓN RECIBIDA', 'operation_category' => 'INVENTARIO'],
            ['code' => '04', 'name' => 'CONSIGNACIÓN ENTREGADA', 'operation_category' => 'INVENTARIO'],
            ['code' => '05', 'name' => 'DEVOLUCIÓN RECIBIDA', 'operation_category' => 'VENTA'],
            ['code' => '06', 'name' => 'DEVOLUCIÓN ENTREGADA', 'operation_category' => 'COMPRA'],
            ['code' => '07', 'name' => 'PROMOCIÓN', 'operation_category' => 'VENTA'],
            ['code' => '08', 'name' => 'PREMIO', 'operation_category' => 'VENTA'],
            ['code' => '09', 'name' => 'DONACIÓN', 'operation_category' => 'GENERAL'],
            ['code' => '10', 'name' => 'SALIDA A PRODUCCIÓN', 'operation_category' => 'COSTOS'],
            ['code' => '11', 'name' => 'TRANSFERENCIA ENTRE ALMACENES', 'operation_category' => 'INVENTARIO'],
            ['code' => '12', 'name' => 'RETIRO', 'operation_category' => 'GENERAL'],
            ['code' => '13', 'name' => 'MERMAS', 'operation_category' => 'INVENTARIO'],
            ['code' => '14', 'name' => 'DESMEDROS', 'operation_category' => 'INVENTARIO'],
            ['code' => '15', 'name' => 'DESTRUCCIÓN', 'operation_category' => 'INVENTARIO'],
            ['code' => '16', 'name' => 'SALDO INICIAL', 'operation_category' => 'APERTURA'],
            ['code' => '99', 'name' => 'OTROS (ESPECIFICAR)', 'operation_category' => 'GENERAL'],
        ];

        if ($this->bulkGlobalInstall) {
            \App\Support\Reference\GlobalReferenceBatchWriter::insert(SunatOperationType::class, $items);
            return;
        }

        foreach ($items as $item) {
            SunatOperationType::query()->updateOrCreate(['code' => $item['code']], $item);
        }
    }
}
