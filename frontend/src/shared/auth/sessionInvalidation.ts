/**
 * Canal centralizado de invalidez de tokens Bearer.
 *
 * El cliente HTTP comunica 401 con el token realmente usado en la solicitud;
 * el store de sesión decide si aún es el token activo (evita que una respuesta
 * tardía de la sesión anterior cierre una sesión recién iniciada).
 *
 * No informa ni envía el token a logs, DOM o servicios externos.
 */
type UnauthorizedHandler = (requestToken: string) => void;
let unauthorizedHandler: UnauthorizedHandler | null = null;

export function setUnauthorizedHandler(handler: UnauthorizedHandler | null): void {
  unauthorizedHandler = handler;
}

export function reportUnauthorizedBearer(requestToken: string | null): void {
  if (requestToken?.trim()) {
    unauthorizedHandler?.(requestToken);
  }
}
