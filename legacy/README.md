# Sistema original (congelado)

Versión previa a la migración, conservada como referencia según la consigna.
**No se modifica.**

- `backend/` — PHP vanilla, arquitectura MVC propia (Pipeline, DAO, DTO)
- `frontend/` — Angular 21, consumía el backend como API

## Modificaciones aplicadas al importarlo

1. `JWT_SECRET` y credenciales de base reemplazados por marcadores.
   El original los tenía escritos en el código, lo que incumple la regla
   de no versionar credenciales. Es el primer hallazgo de seguridad de
   la auditoría.
2. Se excluyeron `vendor/`, `node_modules/`, `.angular/` y un archivo
   comprimido que estaba versionado por error.

El código fuente no fue alterado en ningún otro aspecto.
