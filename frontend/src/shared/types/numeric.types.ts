/**
 * SISTEMA DE TIPADO NUMÉRICO FUERTE AL ESTILO C# (`short`, `int`, `long`, `decimal`)
 * Presente transversalmente en todos los módulos del ERP Contable.
 * Prohibido el uso de `number` en punto flotante para importes o tasas de cambio.
 */

declare const __numericBrand: unique symbol;
export type NumericBrand<TBase, TTag extends string> = TBase & {
  readonly [__numericBrand]: { readonly [K in TTag]: true };
};

/**
 * Equivalente a `System.Int16` (`short` en C#): [-32768 .. 32767]
 */
export type Int16 = NumericBrand<number, 'System.Int16'>;

/**
 * Equivalente a `System.Int32` (`int` en C#): [-2147483648 .. 2147483647]
 */
export type Int32 = NumericBrand<number, 'System.Int32'>;

/**
 * Equivalente a `System.Int64` (`long` seguro en JS para IDs de PostgreSQL BIGINT): [1 .. Number.MAX_SAFE_INTEGER]
 */
export type Int64 = NumericBrand<number, 'System.Int64'>;

/**
 * Representaciones serializadas exactas equivalentes a `decimal` de C# en PostgreSQL:
 * - `Decimal14_4String`: `NUMERIC(14,4)` para importes monetarios
 * - `Decimal14_6String`: `NUMERIC(14,6)` para tipos de cambio
 */
export type Decimal14_4String = NumericBrand<string, 'System.Decimal(14,4)'>;
export type Decimal14_6String = NumericBrand<string, 'System.Decimal(14,6)'>;

export const Int16Type = {
  MIN_VALUE: -32768,
  MAX_VALUE: 32767,
  parse(value: number | string): Int16 {
    const parsed = typeof value === 'number' ? value : Number(value);
    if (!Number.isInteger(parsed) || parsed < this.MIN_VALUE || parsed > this.MAX_VALUE) {
      throw new RangeError(`Valor [${String(value)}] fuera de rango para System.Int16 (short).`);
    }
    return parsed as Int16;
  },
} as const;

export const Int32Type = {
  MIN_VALUE: -2147483648,
  MAX_VALUE: 2147483647,
  parse(value: number | string): Int32 {
    const parsed = typeof value === 'number' ? value : Number(value);
    if (!Number.isInteger(parsed) || parsed < this.MIN_VALUE || parsed > this.MAX_VALUE) {
      throw new RangeError(`Valor [${String(value)}] fuera de rango para System.Int32 (int).`);
    }
    return parsed as Int32;
  },
} as const;

export const Int64Type = {
  parsePositive(value: number | string): Int64 {
    const parsed = typeof value === 'number' ? value : Number(value);
    if (!Number.isSafeInteger(parsed) || parsed <= 0) {
      throw new RangeError(
        `Valor [${String(value)}] inválido: se requiere un System.Int64 (long) positivo.`,
      );
    }
    return parsed as Int64;
  },
} as const;

/**
 * Clase inmutable `SystemDecimal` inspirada en `System.Decimal` de C#.
 * Opera internamente con `bigint` escalado para garantizar precisión matemática absoluta
 * sin errores de coma flotante IEEE 754 en el navegador.
 */
export class SystemDecimal {
  public static readonly MONETARY_SCALE = 4;
  public static readonly EXCHANGE_RATE_SCALE = 6;

  private readonly scaledUnits: bigint;
  public readonly scale: number;

  private constructor(scaledUnits: bigint, scale: number) {
    this.scaledUnits = scaledUnits;
    this.scale = scale;
  }

  public static zero(scale: number = SystemDecimal.MONETARY_SCALE): SystemDecimal {
    return new SystemDecimal(0n, scale);
  }

  public static parseMonetary(raw: string): SystemDecimal {
    return SystemDecimal.parse(raw, SystemDecimal.MONETARY_SCALE);
  }

  public static parseExchangeRate(raw: string): SystemDecimal {
    return SystemDecimal.parse(raw, SystemDecimal.EXCHANGE_RATE_SCALE);
  }

  /**
   * Alias estilo C# (`decimal.Parse`) para tipos de cambio NUMERIC(14,6).
   */
  public static fromExchangeRate(raw: string): SystemDecimal {
    return SystemDecimal.parseExchangeRate(raw);
  }

  /**
   * Alias estilo C# (`decimal.Parse`) para importes monetarios NUMERIC(14,4).
   */
  public static fromMonetary(raw: string): SystemDecimal {
    return SystemDecimal.parseMonetary(raw);
  }

  /**
   * Formato de presentación con separador de miles y N decimales visibles
   * (truncado exacto sobre `bigint`, sin pasar nunca por `number`).
   */
  public toDisplay(visibleDecimals: number = 2): string {
    const raw = this.toString();
    const isNegative = raw.startsWith('-');
    const [intPart = '0', fracPart = ''] = (isNegative ? raw.slice(1) : raw).split('.');
    const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const frac = fracPart.padEnd(visibleDecimals, '0').slice(0, visibleDecimals);
    return `${isNegative ? '-' : ''}${grouped}${visibleDecimals > 0 ? `.${frac}` : ''}`;
  }

  public static parse(raw: string, scale: number = SystemDecimal.MONETARY_SCALE): SystemDecimal {
    const trimmed = raw.trim();
    const match = /^([+-])?(\d+)(?:\.(\d+))?$/.exec(trimmed);
    if (!match) {
      throw new SyntaxError(
        `Formato inválido para System.Decimal: "${raw}". Use representación decimal exacta.`,
      );
    }

    const sign = match[1] === '-' ? -1n : 1n;
    const integerPart = match[2] ?? '0';
    const fractionPart = (match[3] ?? '').padEnd(scale, '0').slice(0, scale);
    const combined = BigInt(integerPart + fractionPart) * sign;

    return new SystemDecimal(combined, scale);
  }

  public add(other: SystemDecimal): SystemDecimal {
    this.assertSameScale(other);
    return new SystemDecimal(this.scaledUnits + other.scaledUnits, this.scale);
  }

  public subtract(other: SystemDecimal): SystemDecimal {
    this.assertSameScale(other);
    return new SystemDecimal(this.scaledUnits - other.scaledUnits, this.scale);
  }

  public equals(other: SystemDecimal): boolean {
    this.assertSameScale(other);
    return this.scaledUnits === other.scaledUnits;
  }

  public isZero(): boolean {
    return this.scaledUnits === 0n;
  }

  public isPositive(): boolean {
    return this.scaledUnits > 0n;
  }

  public toString(): string {
    const isNegative = this.scaledUnits < 0n;
    const absStr = (isNegative ? -this.scaledUnits : this.scaledUnits)
      .toString()
      .padStart(this.scale + 1, '0');
    const intPart = absStr.slice(0, absStr.length - this.scale);
    const fracPart = absStr.slice(absStr.length - this.scale);
    return `${isNegative ? '-' : ''}${intPart}.${fracPart}`;
  }

  public toMonetaryString(): Decimal14_4String {
    if (this.scale !== SystemDecimal.MONETARY_SCALE) {
      throw new Error('La instancia actual no tiene escala monetaria (4).');
    }
    return this.toString() as Decimal14_4String;
  }

  private assertSameScale(other: SystemDecimal): void {
    if (this.scale !== other.scale) {
      throw new Error(
        `Incompatibilidad de escala en SystemDecimal: [${this.scale}] vs [${other.scale}].`,
      );
    }
  }
}
