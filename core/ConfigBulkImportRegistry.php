<?php
/**
 * Registro central de modulos de parametrizacion masiva.
 */

class ConfigBulkImportRegistry
{
    public static function all(): array
    {
        return [
            'categorias' => [
                'key' => 'categorias',
                'label' => 'Categorias',
                'title' => 'Carga masiva de categorias y rutas',
                'description' => 'Cree categorias de proveedores con sus tipos permitidos y estado activo desde una plantilla XLSX.',
                'short_description' => 'Categorias y rutas de proveedores',
                'scope' => 'proveedores',
                'importer_class' => CategoriaBulkImporter::class,
                'target_path' => APP_URL . '/configuracion/proveedores.php',
                'target_label' => 'Volver a proveedores',
                'entity_singular' => 'categoria',
                'entity_plural' => 'categorias',
                'created_title' => 'Categorias creadas',
                'created_columns' => [
                    ['key' => 'row_number', 'label' => 'Fila'],
                    ['key' => 'codigo', 'label' => 'Codigo'],
                    ['key' => 'nombre', 'label' => 'Nombre'],
                    ['key' => 'tipos_permitidos', 'label' => 'Tipos permitidos'],
                    ['key' => 'activo', 'label' => 'Activo'],
                ],
            ],
            'proveedores' => [
                'key' => 'proveedores',
                'label' => 'Proveedores',
                'title' => 'Carga masiva de proveedores',
                'description' => 'Parametrice proveedores desde Excel usando el catalogo vigente de categorias y validando repetidos, tipos y datos obligatorios.',
                'short_description' => 'Proveedores y productores',
                'scope' => 'proveedores',
                'importer_class' => ProveedorBulkImporter::class,
                'target_path' => APP_URL . '/configuracion/proveedores.php',
                'target_label' => 'Volver a proveedores',
                'entity_singular' => 'proveedor',
                'entity_plural' => 'proveedores',
                'created_title' => 'Proveedores creados',
                'created_columns' => [
                    ['key' => 'row_number', 'label' => 'Fila'],
                    ['key' => 'codigo', 'label' => 'Codigo'],
                    ['key' => 'codigo_identificacion', 'label' => 'Cod. identificacion'],
                    ['key' => 'nombre', 'label' => 'Nombre'],
                    ['key' => 'tipo', 'label' => 'Tipo'],
                    ['key' => 'categoria', 'label' => 'Categoria'],
                    ['key' => 'activo', 'label' => 'Activo'],
                ],
            ],
            'variedades' => [
                'key' => 'variedades',
                'label' => 'Variedades',
                'title' => 'Carga masiva de variedades',
                'description' => 'Registre variedades de cacao en bloque con codigo, nombre, descripcion y estado activo.',
                'short_description' => 'Catalogo de variedades de cacao',
                'scope' => 'configuracion_variedades',
                'importer_class' => VariedadBulkImporter::class,
                'target_path' => APP_URL . '/configuracion/variedades.php',
                'target_label' => 'Volver a variedades',
                'entity_singular' => 'variedad',
                'entity_plural' => 'variedades',
                'created_title' => 'Variedades creadas',
                'created_columns' => [
                    ['key' => 'row_number', 'label' => 'Fila'],
                    ['key' => 'codigo', 'label' => 'Codigo'],
                    ['key' => 'nombre', 'label' => 'Nombre'],
                    ['key' => 'descripcion', 'label' => 'Descripcion'],
                    ['key' => 'activo', 'label' => 'Activo'],
                ],
            ],
            'secadoras' => [
                'key' => 'secadoras',
                'label' => 'Secadoras',
                'title' => 'Carga masiva de secadoras',
                'description' => 'Cree secadoras y tendales con numero, tipo, capacidad, ubicacion y estado desde una sola plantilla.',
                'short_description' => 'Catalogo operativo de secadoras',
                'scope' => 'configuracion_secadoras',
                'importer_class' => SecadoraBulkImporter::class,
                'target_path' => APP_URL . '/configuracion/secadoras.php',
                'target_label' => 'Volver a secadoras',
                'entity_singular' => 'secadora',
                'entity_plural' => 'secadoras',
                'created_title' => 'Secadoras creadas',
                'created_columns' => [
                    ['key' => 'row_number', 'label' => 'Fila'],
                    ['key' => 'numero', 'label' => 'Numero'],
                    ['key' => 'nombre', 'label' => 'Nombre'],
                    ['key' => 'tipo', 'label' => 'Tipo'],
                    ['key' => 'capacidad_qq', 'label' => 'Capacidad (qq)'],
                    ['key' => 'activo', 'label' => 'Activo'],
                ],
            ],
            'cajones' => [
                'key' => 'cajones',
                'label' => 'Cajones',
                'title' => 'Carga masiva de cajones de fermentacion',
                'description' => 'Registre cajones en bloque con numero, capacidad, material, ubicacion y estado activo.',
                'short_description' => 'Catalogo de cajones de fermentacion',
                'scope' => 'configuracion_cajones',
                'importer_class' => CajonBulkImporter::class,
                'target_path' => APP_URL . '/configuracion/cajones.php',
                'target_label' => 'Volver a cajones',
                'entity_singular' => 'cajon',
                'entity_plural' => 'cajones',
                'created_title' => 'Cajones creados',
                'created_columns' => [
                    ['key' => 'row_number', 'label' => 'Fila'],
                    ['key' => 'numero', 'label' => 'Numero'],
                    ['key' => 'nombre', 'label' => 'Nombre'],
                    ['key' => 'capacidad_kg', 'label' => 'Capacidad (kg)'],
                    ['key' => 'material', 'label' => 'Material'],
                    ['key' => 'activo', 'label' => 'Activo'],
                ],
            ],
        ];
    }

    public static function get(string $moduleKey): ?array
    {
        $modules = self::all();
        return $modules[$moduleKey] ?? null;
    }

    public static function firstAccessibleKey(): ?string
    {
        $modules = self::accessible();
        $first = array_key_first($modules);
        return $first !== null ? (string) $first : null;
    }

    public static function accessible(): array
    {
        $modules = [];

        foreach (self::all() as $key => $module) {
            if (self::isModuleAllowed($key)) {
                $modules[$key] = $module;
            }
        }

        return $modules;
    }

    public static function isModuleAllowed(string $moduleKey): bool
    {
        $module = self::get($moduleKey);
        if ($module === null) {
            return false;
        }

        return match ($module['scope']) {
            'proveedores' => Auth::isAdmin() || Auth::hasModuleAccess('proveedores'),
            'configuracion_variedades' => Auth::canManageVariedades(),
            'configuracion_secadoras' => Auth::canManageSecadoras(),
            'configuracion_cajones' => Auth::canManageCajones(),
            default => false,
        };
    }

    public static function canAccessAnyModule(): bool
    {
        return !empty(self::accessible());
    }

    public static function createImporter(string $moduleKey): object
    {
        $module = self::get($moduleKey);
        if ($module === null) {
            throw new RuntimeException('El modulo solicitado no existe.');
        }

        $className = (string) $module['importer_class'];
        return new $className();
    }
}
