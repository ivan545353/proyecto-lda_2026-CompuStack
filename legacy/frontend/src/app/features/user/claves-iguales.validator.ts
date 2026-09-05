import { AbstractControl, ValidationErrors } from '@angular/forms';

/**
 * Validador a nivel de grupo: verifica que 'clave' y 'confirmarClave'
 * coincidan. Si 'clave' está vacía (caso edición sin cambiar clave),
 * se considera válido.
 */
export function clavesIguales(group: AbstractControl): ValidationErrors | null {
    const clave = group.get('clave')?.value ?? '';
    const confirmar = group.get('confirmarClave')?.value ?? '';
    if (clave === '' && confirmar === '') return null;
    return clave === confirmar ? null : { clavesNoCoinciden: true };
}