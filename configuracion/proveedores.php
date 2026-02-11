<?php
/**
 * Gestión de Proveedores - Megablessing
 * CRUD completo para proveedores/rutas de cacao
 */

require_once __DIR__ . '/../bootstrap.php';

requireAuth();
if (!Auth::isAdmin() && !Auth::hasRole('Supervisor') && !Auth::hasPermission('configuracion')) {
    setFlash('danger', 'No tiene permisos para acceder a esta sección.');
    redirect('/dashboard.php');
}

$db = Database::getInstance()->getConnection();
$csrfToken = generateCsrfToken();

$getTableColumns = static function (PDO $pdo, string $table): array {
    return array_column(
        $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
};

$normalizarCategoria = static function (string $valor): string {
    $valor = strtoupper(trim($valor));
    return strtr($valor, [
        'Á' => 'A',
        'É' => 'E',
        'Í' => 'I',
        'Ó' => 'O',
        'Ú' => 'U',
    ]);
};

$tiposProveedor = ['MERCADO', 'BODEGA', 'RUTA', 'PRODUCTOR'];
$categoriasSeed = [
    ['codigo' => 'M', 'nombre' => 'Mercado', 'tipo' => 'MERCADO', 'categoria' => 'MERCADO'],
    ['codigo' => 'B', 'nombre' => 'Bodega', 'tipo' => 'BODEGA', 'categoria' => 'BODEGA'],
    ['codigo' => 'ES', 'nombre' => 'Esmeraldas', 'tipo' => 'RUTA', 'categoria' => 'ESMERALDAS'],
    ['codigo' => 'FM', 'nombre' => 'Flor de Manabí', 'tipo' => 'RUTA', 'categoria' => 'FLOR DE MANABI'],
    ['codigo' => 'VP', 'nombre' => 'Vía Pedernales', 'tipo' => 'RUTA', 'categoria' => 'VIA PEDERNALES'],
];
$categoriasPorTipo = [];
$categoriaLabels = [];
$categoriasProveedor = [];
$tipoPorCategoria = [];
$categoriasCatalogo = [];

$colsLotes = $getTableColumns($db, 'lotes');
$exprPesoRecepcion = in_array('peso_recepcion_kg', $colsLotes, true)
    ? 'peso_recepcion_kg'
    : (in_array('peso_inicial_kg', $colsLotes, true) ? 'peso_inicial_kg' : '0');

$colsProveedores = $getTableColumns($db, 'proveedores');
$hasProvCol = static function (string $name) use (&$colsProveedores): bool {
    return in_array($name, $colsProveedores, true);
};

// Asegurar columnas nuevas para parametrización de proveedores.
$schemaStatements = [];
if (!$hasProvCol('cedula_ruc')) {
    $schemaStatements[] = "ALTER TABLE proveedores ADD COLUMN cedula_ruc VARCHAR(20) NULL AFTER nombre";
}
if (!$hasProvCol('codigo_identificacion')) {
    $schemaStatements[] = "ALTER TABLE proveedores ADD COLUMN codigo_identificacion VARCHAR(20) NULL AFTER codigo";
}
if (!$hasProvCol('categoria')) {
    $schemaStatements[] = "ALTER TABLE proveedores ADD COLUMN categoria VARCHAR(100) NULL AFTER tipo";
}
if (!$hasProvCol('es_categoria')) {
    $schemaStatements[] = "ALTER TABLE proveedores ADD COLUMN es_categoria TINYINT(1) NOT NULL DEFAULT 0 AFTER categoria";
}
if (!$hasProvCol('email')) {
    $schemaStatements[] = "ALTER TABLE proveedores ADD COLUMN email VARCHAR(120) NULL AFTER telefono";
}

foreach ($schemaStatements as $statement) {
    try {
        $db->exec($statement);
    } catch (Throwable $e) {
        // Continuar: el módulo funciona en modo compatibilidad aunque no se pueda alterar esquema.
    }
}
$colsProveedores = $getTableColumns($db, 'proveedores');

if ($hasProvCol('es_categoria')) {
    // Marcar registros base como categorías para separar tipos de proveedores reales.
    try {
        $codigosSemilla = array_map(static fn(array $seed): string => strtoupper($seed['codigo']), $categoriasSeed);
        if (!empty($codigosSemilla)) {
            $placeholders = implode(', ', array_fill(0, count($codigosSemilla), '?'));
            $stmt = $db->prepare("UPDATE proveedores SET es_categoria = 1 WHERE UPPER(codigo) IN ({$placeholders})");
            $stmt->execute($codigosSemilla);
        }
    } catch (Throwable $e) {
        // Compatibilidad: continuar incluso si falla el ajuste.
    }
}

if ($hasProvCol('es_categoria') && $hasProvCol('categoria')) {
    // Asegurar que la categoría base quede normalizada y que existan los registros semilla.
    foreach ($categoriasSeed as $seed) {
        $categoriaClave = $normalizarCategoria($seed['categoria']);
        $tipoSeed = strtoupper($seed['tipo']);
        try {
            $stmt = $db->prepare(
                "SELECT id, categoria FROM proveedores WHERE es_categoria = 1 AND (UPPER(codigo) = ? OR categoria = ?) LIMIT 1"
            );
            $stmt->execute([strtoupper($seed['codigo']), $categoriaClave]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $stmtUpdate = $db->prepare("UPDATE proveedores SET categoria = ?, tipo = ? WHERE id = ?");
                $stmtUpdate->execute([$categoriaClave, $tipoSeed, (int)$existing['id']]);
                continue;
            }

            $stmtByCode = $db->prepare("SELECT id FROM proveedores WHERE UPPER(codigo) = ? LIMIT 1");
            $stmtByCode->execute([strtoupper($seed['codigo'])]);
            if ($stmtByCode->fetch()) {
                continue;
            }

            $insertSeed = [
                'codigo' => strtoupper($seed['codigo']),
                'nombre' => $seed['nombre'],
                'tipo' => $tipoSeed,
                'categoria' => $categoriaClave,
                'activo' => 1,
                'es_categoria' => 1,
            ];
            if ($hasProvCol('codigo_identificacion')) {
                $insertSeed['codigo_identificacion'] = null;
            }
            if ($hasProvCol('cedula_ruc')) {
                $insertSeed['cedula_ruc'] = null;
            }
            if ($hasProvCol('email')) {
                $insertSeed['email'] = null;
            }
            if ($hasProvCol('contacto')) {
                $insertSeed['contacto'] = null;
            }
            if ($hasProvCol('direccion')) {
                $insertSeed['direccion'] = null;
            }
            if ($hasProvCol('telefono')) {
                $insertSeed['telefono'] = null;
            }

            $columnsSeed = array_keys($insertSeed);
            $valuesSeed = array_values($insertSeed);
            $phSeed = implode(', ', array_fill(0, count($columnsSeed), '?'));
            $stmtInsert = $db->prepare(
                "INSERT INTO proveedores (" . implode(', ', $columnsSeed) . ") VALUES ({$phSeed})"
            );
            $stmtInsert->execute($valuesSeed);
        } catch (Throwable $e) {
            // Continuar en modo compatibilidad.
        }
    }
}

if ($hasProvCol('es_categoria')) {
    try {
        $stmtCategorias = $db->query(
            "SELECT id, codigo, nombre, tipo, categoria, activo
             FROM proveedores
             WHERE es_categoria = 1
             ORDER BY activo DESC, tipo ASC, nombre ASC"
        );
        $categoriasCatalogo = $stmtCategorias->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $categoriasCatalogo = [];
    }
}

if (empty($categoriasCatalogo)) {
    foreach ($categoriasSeed as $seed) {
        $categoriasCatalogo[] = [
            'id' => 0,
            'codigo' => strtoupper($seed['codigo']),
            'nombre' => $seed['nombre'],
            'tipo' => strtoupper($seed['tipo']),
            'categoria' => $normalizarCategoria($seed['categoria']),
            'activo' => 1,
        ];
    }
}

foreach ($tiposProveedor as $tipoBase) {
    $categoriasPorTipo[$tipoBase] = [];
}

foreach ($categoriasCatalogo as $catRow) {
    $categoriaKey = $normalizarCategoria((string)($catRow['categoria'] ?? $catRow['nombre'] ?? ''));
    if ($categoriaKey === '') {
        continue;
    }

    $tipoCat = strtoupper(trim((string)($catRow['tipo'] ?? 'RUTA')));
    if (!in_array($tipoCat, $tiposProveedor, true)) {
        $tipoCat = 'RUTA';
    }
    $labelCat = trim((string)($catRow['nombre'] ?? $categoriaKey));
    if ($labelCat === '') {
        $labelCat = $categoriaKey;
    }

    if (!in_array($categoriaKey, $categoriasPorTipo[$tipoCat], true)) {
        $categoriasPorTipo[$tipoCat][] = $categoriaKey;
    }
    if (!in_array($categoriaKey, $categoriasProveedor, true)) {
        $categoriasProveedor[] = $categoriaKey;
    }
    $tipoPorCategoria[$categoriaKey] = $tipoCat;
    $categoriaLabels[$categoriaKey] = $labelCat;
}

$generarCodigoIdentificacion = static function (PDO $pdo): string {
    $stmt = $pdo->query("SELECT codigo_identificacion FROM proveedores WHERE codigo_identificacion LIKE 'PRO-%'");
    $max = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $valor = strtoupper(trim((string)($row['codigo_identificacion'] ?? '')));
        if (preg_match('/^PRO-(\d{5})$/', $valor, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }

    return 'PRO-' . str_pad((string)($max + 1), 5, '0', STR_PAD_LEFT);
};

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    header('Content-Type: application/json');

    $requestToken = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!$requestToken || !verifyCsrfToken($requestToken)) {
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido. Recargue la página e intente de nuevo.']);
        exit;
    }
    
    try {
        switch ($action) {
            case 'create_category':
                $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
                $nombre = trim($_POST['nombre'] ?? '');
                $tipo = strtoupper(trim($_POST['tipo'] ?? 'RUTA'));
                $categoria = $normalizarCategoria($nombre);

                if ($codigo === '' || $nombre === '') {
                    throw new Exception('Código y nombre de categoría son obligatorios');
                }
                if (!in_array($tipo, $tiposProveedor, true)) {
                    throw new Exception('Tipo de categoría no válido');
                }
                if (!$hasProvCol('categoria')) {
                    throw new Exception('La columna de categoría no está disponible');
                }

                $stmt = $db->prepare("SELECT id FROM proveedores WHERE codigo = ?");
                $stmt->execute([$codigo]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya existe un registro con este código');
                }

                if ($hasProvCol('es_categoria')) {
                    $stmt = $db->prepare("SELECT id FROM proveedores WHERE es_categoria = 1 AND categoria = ?");
                    $stmt->execute([$categoria]);
                    if ($stmt->fetch()) {
                        throw new Exception('Ya existe esta categoría');
                    }
                }

                $insertData = [
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'tipo' => $tipo,
                    'categoria' => $categoria,
                    'activo' => 1,
                ];
                if ($hasProvCol('es_categoria')) {
                    $insertData['es_categoria'] = 1;
                }
                if ($hasProvCol('codigo_identificacion')) {
                    $insertData['codigo_identificacion'] = null;
                }
                if ($hasProvCol('cedula_ruc')) {
                    $insertData['cedula_ruc'] = null;
                }
                if ($hasProvCol('email')) {
                    $insertData['email'] = null;
                }
                if ($hasProvCol('contacto')) {
                    $insertData['contacto'] = null;
                }
                if ($hasProvCol('direccion')) {
                    $insertData['direccion'] = null;
                }
                if ($hasProvCol('telefono')) {
                    $insertData['telefono'] = null;
                }

                $columns = array_keys($insertData);
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $stmt = $db->prepare(
                    "INSERT INTO proveedores (" . implode(', ', $columns) . ") VALUES ({$placeholders})"
                );
                $stmt->execute(array_values($insertData));

                echo json_encode(['success' => true, 'message' => 'Categoría creada exitosamente']);
                break;

            case 'update_category':
                $id = (int)($_POST['id'] ?? 0);
                $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
                $nombre = trim($_POST['nombre'] ?? '');
                $tipo = strtoupper(trim($_POST['tipo'] ?? 'RUTA'));
                $categoriaNueva = $normalizarCategoria($nombre);

                if (!$id || $codigo === '' || $nombre === '') {
                    throw new Exception('Datos incompletos para actualizar la categoría');
                }
                if (!in_array($tipo, $tiposProveedor, true)) {
                    throw new Exception('Tipo de categoría no válido');
                }
                if (!$hasProvCol('categoria')) {
                    throw new Exception('La columna de categoría no está disponible');
                }

                $stmt = $db->prepare("SELECT * FROM proveedores WHERE id = ?");
                $stmt->execute([$id]);
                $actual = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$actual) {
                    throw new Exception('Categoría no encontrada');
                }
                if ($hasProvCol('es_categoria') && (int)($actual['es_categoria'] ?? 0) !== 1) {
                    throw new Exception('El registro seleccionado no es una categoría');
                }

                $stmt = $db->prepare("SELECT id FROM proveedores WHERE codigo = ? AND id != ?");
                $stmt->execute([$codigo, $id]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya existe otro registro con este código');
                }

                $categoriaAnterior = $normalizarCategoria((string)($actual['categoria'] ?? $actual['nombre'] ?? ''));

                $updateData = [
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'tipo' => $tipo,
                    'categoria' => $categoriaNueva,
                ];
                if ($hasProvCol('es_categoria')) {
                    $updateData['es_categoria'] = 1;
                }

                $setClause = implode(', ', array_map(static fn($col) => "{$col} = ?", array_keys($updateData)));
                $paramsUpdate = array_values($updateData);
                $paramsUpdate[] = $id;
                $stmt = $db->prepare("UPDATE proveedores SET {$setClause} WHERE id = ?");
                $stmt->execute($paramsUpdate);

                if ($categoriaAnterior !== '' && $categoriaAnterior !== $categoriaNueva) {
                    if ($hasProvCol('es_categoria')) {
                        $stmt = $db->prepare(
                            "UPDATE proveedores
                             SET tipo = ?, categoria = ?
                             WHERE es_categoria = 0 AND categoria = ?"
                        );
                        $stmt->execute([$tipo, $categoriaNueva, $categoriaAnterior]);
                    } else {
                        $stmt = $db->prepare(
                            "UPDATE proveedores
                             SET tipo = ?, categoria = ?
                             WHERE id != ? AND categoria = ?"
                        );
                        $stmt->execute([$tipo, $categoriaNueva, $id, $categoriaAnterior]);
                    }
                } else {
                    if ($hasProvCol('es_categoria')) {
                        $stmt = $db->prepare(
                            "UPDATE proveedores
                             SET tipo = ?
                             WHERE es_categoria = 0 AND categoria = ?"
                        );
                        $stmt->execute([$tipo, $categoriaNueva]);
                    }
                }

                echo json_encode(['success' => true, 'message' => 'Categoría actualizada exitosamente']);
                break;

            case 'create':
                $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
                $nombre = trim($_POST['nombre'] ?? '');
                $tipo = strtoupper(trim($_POST['tipo'] ?? 'MERCADO'));
                $categoria = $normalizarCategoria((string)($_POST['categoria'] ?? ''));
                $cedulaRuc = preg_replace('/\s+/', '', trim($_POST['cedula_ruc'] ?? ''));
                $codigoIdentificacion = strtoupper(trim($_POST['codigo_identificacion'] ?? ''));
                $direccion = trim($_POST['direccion'] ?? '');
                $telefono = trim($_POST['telefono'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $contacto = trim($_POST['contacto'] ?? '');

                if ($codigo === '' || $nombre === '') {
                    throw new Exception('Código y nombre son obligatorios');
                }

                if ($hasProvCol('categoria')) {
                    if ($categoria !== '' && isset($tipoPorCategoria[$categoria])) {
                        $tipo = $tipoPorCategoria[$categoria];
                    } elseif ($categoria === '' && isset($categoriasPorTipo[$tipo][0])) {
                        $categoria = $categoriasPorTipo[$tipo][0];
                    }
                    if ($categoria === '' || !isset($tipoPorCategoria[$categoria])) {
                        throw new Exception('Seleccione una categoría válida para registrar el proveedor');
                    }
                    $tipo = $tipoPorCategoria[$categoria];
                }

                if (!in_array($tipo, $tiposProveedor, true)) {
                    throw new Exception('Tipo de proveedor no válido');
                }

                if ($hasProvCol('cedula_ruc') && $cedulaRuc !== '' && !preg_match('/^\d{10}(\d{3})?$/', $cedulaRuc)) {
                    throw new Exception('La cédula/RUC debe tener 10 o 13 dígitos numéricos');
                }
                if ($hasProvCol('email') && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('El correo electrónico no es válido');
                }
                if ($hasProvCol('codigo_identificacion')) {
                    if ($codigoIdentificacion === '') {
                        $codigoIdentificacion = $generarCodigoIdentificacion($db);
                    }
                    if (!preg_match('/^PRO-\d{5}$/', $codigoIdentificacion)) {
                        throw new Exception('El código de identificación debe tener formato PRO-00001');
                    }
                }

                $stmt = $db->prepare("SELECT id FROM proveedores WHERE codigo = ?");
                $stmt->execute([$codigo]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya existe un proveedor con este código');
                }
                if ($hasProvCol('codigo_identificacion')) {
                    $stmt = $db->prepare("SELECT id FROM proveedores WHERE codigo_identificacion = ?");
                    $stmt->execute([$codigoIdentificacion]);
                    if ($stmt->fetch()) {
                        throw new Exception('Ya existe un proveedor con este código de identificación');
                    }
                }
                if ($hasProvCol('cedula_ruc') && $cedulaRuc !== '') {
                    $stmt = $db->prepare("SELECT id FROM proveedores WHERE cedula_ruc = ?");
                    $stmt->execute([$cedulaRuc]);
                    if ($stmt->fetch()) {
                        throw new Exception('Ya existe un proveedor con esta cédula/RUC');
                    }
                }

                $insertData = [
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'tipo' => $tipo,
                    'direccion' => $direccion !== '' ? $direccion : null,
                    'telefono' => $telefono !== '' ? $telefono : null,
                    'activo' => 1,
                ];
                if ($hasProvCol('es_categoria')) {
                    $insertData['es_categoria'] = 0;
                }
                if ($hasProvCol('contacto')) {
                    $insertData['contacto'] = $contacto !== '' ? $contacto : null;
                }
                if ($hasProvCol('cedula_ruc')) {
                    $insertData['cedula_ruc'] = $cedulaRuc !== '' ? $cedulaRuc : null;
                }
                if ($hasProvCol('codigo_identificacion')) {
                    $insertData['codigo_identificacion'] = $codigoIdentificacion !== '' ? $codigoIdentificacion : null;
                }
                if ($hasProvCol('categoria')) {
                    $insertData['categoria'] = $categoria !== '' ? $categoria : null;
                }
                if ($hasProvCol('email')) {
                    $insertData['email'] = $email !== '' ? $email : null;
                }

                $columns = array_keys($insertData);
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $stmt = $db->prepare(
                    "INSERT INTO proveedores (" . implode(', ', $columns) . ") VALUES ({$placeholders})"
                );
                $stmt->execute(array_values($insertData));

                echo json_encode(['success' => true, 'message' => 'Proveedor creado exitosamente', 'id' => $db->lastInsertId()]);
                break;

            case 'update':
                $id = (int)($_POST['id'] ?? 0);
                $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
                $nombre = trim($_POST['nombre'] ?? '');
                $tipo = strtoupper(trim($_POST['tipo'] ?? 'MERCADO'));
                $categoria = $normalizarCategoria((string)($_POST['categoria'] ?? ''));
                $cedulaRuc = preg_replace('/\s+/', '', trim($_POST['cedula_ruc'] ?? ''));
                $codigoIdentificacion = strtoupper(trim($_POST['codigo_identificacion'] ?? ''));
                $direccion = trim($_POST['direccion'] ?? '');
                $telefono = trim($_POST['telefono'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $contacto = trim($_POST['contacto'] ?? '');

                if (!$id || $codigo === '' || $nombre === '') {
                    throw new Exception('Datos incompletos');
                }

                $stmt = $db->prepare("SELECT * FROM proveedores WHERE id = ?");
                $stmt->execute([$id]);
                $actual = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$actual) {
                    throw new Exception('Proveedor no encontrado');
                }
                if ($hasProvCol('es_categoria') && (int)($actual['es_categoria'] ?? 0) === 1) {
                    throw new Exception('Use la edición de categoría para este registro');
                }

                if ($hasProvCol('categoria')) {
                    if ($categoria !== '' && isset($tipoPorCategoria[$categoria])) {
                        $tipo = $tipoPorCategoria[$categoria];
                    } elseif ($categoria === '' && isset($categoriasPorTipo[$tipo][0])) {
                        $categoria = $categoriasPorTipo[$tipo][0];
                    }
                    if ($categoria === '' || !isset($tipoPorCategoria[$categoria])) {
                        throw new Exception('Seleccione una categoría válida para el proveedor');
                    }
                    $tipo = $tipoPorCategoria[$categoria];
                }

                if (!in_array($tipo, $tiposProveedor, true)) {
                    throw new Exception('Tipo de proveedor no válido');
                }
                if ($hasProvCol('cedula_ruc') && $cedulaRuc !== '' && !preg_match('/^\d{10}(\d{3})?$/', $cedulaRuc)) {
                    throw new Exception('La cédula/RUC debe tener 10 o 13 dígitos numéricos');
                }
                if ($hasProvCol('email') && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('El correo electrónico no es válido');
                }
                if ($hasProvCol('codigo_identificacion') && $codigoIdentificacion !== '' && !preg_match('/^PRO-\d{5}$/', $codigoIdentificacion)) {
                    throw new Exception('El código de identificación debe tener formato PRO-00001');
                }

                $stmt = $db->prepare("SELECT id FROM proveedores WHERE codigo = ? AND id != ?");
                $stmt->execute([$codigo, $id]);
                if ($stmt->fetch()) {
                    throw new Exception('Ya existe otro proveedor con este código');
                }
                if ($hasProvCol('codigo_identificacion') && $codigoIdentificacion !== '') {
                    $stmt = $db->prepare("SELECT id FROM proveedores WHERE codigo_identificacion = ? AND id != ?");
                    $stmt->execute([$codigoIdentificacion, $id]);
                    if ($stmt->fetch()) {
                        throw new Exception('Ya existe otro proveedor con este código de identificación');
                    }
                }
                if ($hasProvCol('cedula_ruc') && $cedulaRuc !== '') {
                    $stmt = $db->prepare("SELECT id FROM proveedores WHERE cedula_ruc = ? AND id != ?");
                    $stmt->execute([$cedulaRuc, $id]);
                    if ($stmt->fetch()) {
                        throw new Exception('Ya existe otro proveedor con esta cédula/RUC');
                    }
                }

                $updateData = [
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'tipo' => $tipo,
                    'direccion' => $direccion !== '' ? $direccion : null,
                    'telefono' => $telefono !== '' ? $telefono : null,
                ];
                if ($hasProvCol('contacto')) {
                    $updateData['contacto'] = $contacto !== '' ? $contacto : null;
                }
                if ($hasProvCol('cedula_ruc')) {
                    $updateData['cedula_ruc'] = $cedulaRuc !== '' ? $cedulaRuc : null;
                }
                if ($hasProvCol('codigo_identificacion')) {
                    $updateData['codigo_identificacion'] = $codigoIdentificacion !== '' ? $codigoIdentificacion : null;
                }
                if ($hasProvCol('categoria')) {
                    $updateData['categoria'] = $categoria !== '' ? $categoria : null;
                }
                if ($hasProvCol('email')) {
                    $updateData['email'] = $email !== '' ? $email : null;
                }
                if ($hasProvCol('es_categoria')) {
                    $updateData['es_categoria'] = 0;
                }

                $setClause = implode(', ', array_map(static fn($col) => "{$col} = ?", array_keys($updateData)));
                $paramsUpdate = array_values($updateData);
                $paramsUpdate[] = $id;
                $stmt = $db->prepare("UPDATE proveedores SET {$setClause} WHERE id = ?");
                $stmt->execute($paramsUpdate);

                echo json_encode(['success' => true, 'message' => 'Proveedor actualizado exitosamente']);
                break;

            case 'toggle':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) {
                    throw new Exception('ID inválido');
                }

                $stmt = $db->prepare("UPDATE proveedores SET activo = NOT activo WHERE id = ?");
                $stmt->execute([$id]);

                echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
                break;

            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) {
                    throw new Exception('ID inválido');
                }

                $stmt = $db->prepare("SELECT * FROM proveedores WHERE id = ?");
                $stmt->execute([$id]);
                $registro = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$registro) {
                    throw new Exception('Registro no encontrado');
                }

                $stmt = $db->prepare("SELECT COUNT(*) FROM lotes WHERE proveedor_id = ?");
                $stmt->execute([$id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new Exception('No se puede eliminar: tiene lotes asociados. Desactive el registro en su lugar.');
                }

                if ($hasProvCol('es_categoria') && (int)($registro['es_categoria'] ?? 0) === 1) {
                    $catKey = $normalizarCategoria((string)($registro['categoria'] ?? $registro['nombre'] ?? ''));
                    if ($catKey !== '') {
                        $stmt = $db->prepare(
                            "SELECT COUNT(*) FROM proveedores WHERE es_categoria = 0 AND categoria = ?"
                        );
                        $stmt->execute([$catKey]);
                        if ((int)$stmt->fetchColumn() > 0) {
                            throw new Exception('No se puede eliminar: la categoría tiene proveedores asociados.');
                        }
                    }
                }

                $stmt = $db->prepare("DELETE FROM proveedores WHERE id = ?");
                $stmt->execute([$id]);

                echo json_encode(['success' => true, 'message' => 'Registro eliminado']);
                break;

            case 'get':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) {
                    throw new Exception('ID inválido');
                }

                $stmt = $db->prepare("SELECT * FROM proveedores WHERE id = ?");
                $stmt->execute([$id]);
                $proveedor = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$proveedor) {
                    throw new Exception('Registro no encontrado');
                }

                echo json_encode(['success' => true, 'data' => $proveedor]);
                break;

            default:
                throw new Exception('Acción no válida');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Obtener proveedores para listado
$search = trim($_GET['search'] ?? '');
$tipo_filter = strtoupper(trim($_GET['tipo'] ?? ''));
$categoria_filter = $normalizarCategoria((string)($_GET['categoria'] ?? ''));
$estado_filter = $_GET['estado'] ?? '';

if ($tipo_filter !== '' && !in_array($tipo_filter, $tiposProveedor, true)) {
    $tipo_filter = '';
}
if ($categoria_filter !== '' && !in_array($categoria_filter, $categoriasProveedor, true)) {
    $categoria_filter = '';
}

$where = [];
$params = [];

if ($hasProvCol('es_categoria')) {
    $where[] = "(p.es_categoria = 0 OR p.es_categoria IS NULL)";
}

if ($search) {
    $searchColumns = ['codigo', 'nombre'];
    if ($hasProvCol('contacto')) {
        $searchColumns[] = 'contacto';
    }
    if ($hasProvCol('codigo_identificacion')) {
        $searchColumns[] = 'codigo_identificacion';
    }
    if ($hasProvCol('cedula_ruc')) {
        $searchColumns[] = 'cedula_ruc';
    }
    if ($hasProvCol('email')) {
        $searchColumns[] = 'email';
    }

    $searchConditions = [];
    foreach ($searchColumns as $col) {
        $searchConditions[] = "{$col} LIKE ?";
        $params[] = "%{$search}%";
    }
    $where[] = '(' . implode(' OR ', $searchConditions) . ')';
}
if ($tipo_filter) {
    $where[] = "tipo = ?";
    $params[] = $tipo_filter;
}
if ($categoria_filter && $hasProvCol('categoria')) {
    $where[] = "categoria = ?";
    $params[] = $categoria_filter;
}
if ($estado_filter !== '') {
    $where[] = "activo = ?";
    $params[] = (int)$estado_filter;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT p.*, 
           (SELECT COUNT(*) FROM lotes WHERE proveedor_id = p.id) as total_lotes,
           (SELECT COALESCE(SUM({$exprPesoRecepcion}), 0) FROM lotes WHERE proveedor_id = p.id) as peso_total
    FROM proveedores p
    $whereClause
    ORDER BY p.activo DESC, p.nombre ASC
");
$stmt->execute($params);
$proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoriasVista = [];
if ($hasProvCol('es_categoria') && $hasProvCol('categoria')) {
    $stmtCategorias = $db->query("
        SELECT c.*,
               (SELECT COUNT(*) FROM proveedores p2
                WHERE (p2.es_categoria = 0 OR p2.es_categoria IS NULL)
                  AND p2.categoria = c.categoria) as total_proveedores,
               (SELECT COUNT(*) FROM lotes l WHERE l.proveedor_id = c.id) as total_lotes_directos
        FROM proveedores c
        WHERE c.es_categoria = 1
        ORDER BY c.activo DESC, c.tipo ASC, c.nombre ASC
    ");
    $categoriasVista = $stmtCategorias->fetchAll(PDO::FETCH_ASSOC);
}
$categoriasActivasCount = 0;
if (!empty($categoriasVista)) {
    foreach ($categoriasVista as $cat) {
        if ((int)($cat['activo'] ?? 1) === 1) {
            $categoriasActivasCount++;
        }
    }
} elseif (!empty($categoriasCatalogo)) {
    foreach ($categoriasCatalogo as $cat) {
        if ((int)($cat['activo'] ?? 1) === 1) {
            $categoriasActivasCount++;
        }
    }
}

// Estadísticas
if ($hasProvCol('es_categoria')) {
    $stats = $db->query("
        SELECT
            SUM(es_categoria = 1) as categorias,
            SUM(es_categoria = 0 OR es_categoria IS NULL) as total,
            SUM((es_categoria = 0 OR es_categoria IS NULL) AND activo = 1) as activos,
            SUM((es_categoria = 0 OR es_categoria IS NULL) AND tipo = 'MERCADO') as mercado,
            SUM((es_categoria = 0 OR es_categoria IS NULL) AND tipo = 'BODEGA') as bodega,
            SUM((es_categoria = 0 OR es_categoria IS NULL) AND tipo = 'RUTA') as ruta,
            SUM((es_categoria = 0 OR es_categoria IS NULL) AND tipo = 'PRODUCTOR') as productor
        FROM proveedores
    ")->fetch(PDO::FETCH_ASSOC);
} else {
    $stats = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(activo = 1) as activos,
            SUM(tipo = 'MERCADO') as mercado,
            SUM(tipo = 'BODEGA') as bodega,
            SUM(tipo = 'RUTA') as ruta,
            SUM(tipo = 'PRODUCTOR') as productor
        FROM proveedores
    ")->fetch(PDO::FETCH_ASSOC);
    $stats['categorias'] = count($categoriasCatalogo);
}

$pageTitle = 'Gestión de Proveedores';
$pageSubtitle = 'Administre los proveedores y rutas de abastecimiento de cacao';
ob_start();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex items-center gap-4 mb-4">
            <a href="<?= APP_URL ?>/configuracion/index.php" class="text-gray-500 hover:text-primary transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
            </a>
            <div>
                <h1 class="text-3xl font-bold text-primary">Gestión de Proveedores</h1>
                <p class="text-warmgray mt-1">Administre los proveedores y rutas de abastecimiento de cacao</p>
            </div>
        </div>
    </div>

    <!-- Estadísticas -->
    <div class="grid grid-cols-2 md:grid-cols-7 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-primary"><?= number_format($stats['total']) ?></div>
            <div class="text-sm text-warmgray">Proveedores</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-slate-700"><?= number_format((int)($stats['categorias'] ?? 0)) ?></div>
            <div class="text-sm text-warmgray">Categorías</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-emerald-600"><?= number_format($stats['activos']) ?></div>
            <div class="text-sm text-warmgray">Activos</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-blue-600"><?= number_format($stats['mercado'] ?? 0) ?></div>
            <div class="text-sm text-warmgray">Mercado</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-amber-600"><?= number_format($stats['bodega'] ?? 0) ?></div>
            <div class="text-sm text-warmgray">Bodega</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-purple-600"><?= number_format($stats['ruta'] ?? 0) ?></div>
            <div class="text-sm text-warmgray">Ruta</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-4 text-center">
            <div class="text-3xl font-bold text-teal-600"><?= number_format($stats['productor'] ?? 0) ?></div>
            <div class="text-sm text-warmgray">Productor</div>
        </div>
    </div>

    <!-- Filtros y Acciones -->
    <div class="bg-white rounded-xl shadow-sm border border-olive/20 p-6 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Acciones</h2>
                <p class="text-sm text-gray-500">Primero cree la categoría/tipo y luego registre proveedores dentro de esa categoría.</p>
                <a href="#" onclick="openProveedorModal('create'); return false;" class="js-open-create-proveedor inline-flex mt-2 text-primary font-semibold hover:underline">
                    + Crear proveedor
                </a>
            </div>
            <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                <button type="button" onclick="openCategoriaModal('create')" class="w-full sm:w-auto px-6 py-2 border border-primary text-primary rounded-lg hover:bg-primary/10 transition-colors flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h8m-8 5h8m-8 5h8M5 7h.01M5 12h.01M5 17h.01"/>
                    </svg>
                    Nueva Categoría/Tipo
                </button>
                <button type="button" onclick="openProveedorModal('create')" class="js-open-create-proveedor w-full sm:w-auto px-6 py-2 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                    Nuevo Proveedor
                </button>
            </div>
        </div>
        <?php if ($categoriasActivasCount === 0): ?>
        <div class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-amber-700 text-sm">
            No hay categorías activas registradas. Cree una categoría para habilitar el registro de proveedores.
        </div>
        <?php endif; ?>
        <?php if ($hasProvCol('es_categoria')): ?>
        <div class="mb-5 overflow-x-auto border border-gray-200 rounded-lg">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Código</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Categoría/Tipo</th>
                        <th class="px-4 py-3 text-left font-semibold text-gray-700">Tipo</th>
                        <th class="px-4 py-3 text-center font-semibold text-gray-700">Proveedores</th>
                        <th class="px-4 py-3 text-center font-semibold text-gray-700">Lotes directos</th>
                        <th class="px-4 py-3 text-center font-semibold text-gray-700">Estado</th>
                        <th class="px-4 py-3 text-right font-semibold text-gray-700">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($categoriasVista as $cat): ?>
                    <?php $catKey = $normalizarCategoria((string)($cat['categoria'] ?? $cat['nombre'] ?? '')); ?>
                    <tr class="<?= ((int)($cat['activo'] ?? 1) === 0) ? 'opacity-60' : '' ?>">
                        <td class="px-4 py-3 font-mono font-semibold text-primary"><?= htmlspecialchars($cat['codigo']) ?></td>
                        <td class="px-4 py-3"><?= htmlspecialchars($cat['nombre']) ?></td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700"><?= htmlspecialchars($cat['tipo']) ?></span>
                        </td>
                        <td class="px-4 py-3 text-center font-semibold text-primary"><?= number_format((int)($cat['total_proveedores'] ?? 0)) ?></td>
                        <td class="px-4 py-3 text-center font-semibold text-primary"><?= number_format((int)($cat['total_lotes_directos'] ?? 0)) ?></td>
                        <td class="px-4 py-3 text-center">
                            <button type="button" onclick="toggleEstado(<?= (int)$cat['id'] ?>)"
                                    class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium transition-colors
                                           <?= ((int)($cat['activo'] ?? 1) === 1) ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' : 'bg-red-100 text-red-800 hover:bg-red-200' ?>">
                                <span class="w-2 h-2 rounded-full <?= ((int)($cat['activo'] ?? 1) === 1) ? 'bg-emerald-500' : 'bg-red-500' ?>"></span>
                                <?= ((int)($cat['activo'] ?? 1) === 1) ? 'Activo' : 'Inactivo' ?>
                            </button>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <button type="button" onclick="openProveedorModal('create', null, '<?= htmlspecialchars($catKey) ?>')"
                                        class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-primary text-white hover:bg-primary/90 transition-colors">
                                    + Proveedor
                                </button>
                                <button type="button" onclick="openCategoriaModal('edit', <?= (int)$cat['id'] ?>)"
                                        class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Editar categoría">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <form method="GET" class="flex flex-wrap gap-4 items-center">
            <div class="flex-1 min-w-[240px]">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Buscar por código, empresa, cédula/RUC, correo..."
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
            </div>
            <select name="tipo" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary">
                <option value="">Todos los tipos</option>
                <option value="MERCADO" <?= $tipo_filter === 'MERCADO' ? 'selected' : '' ?>>Mercado</option>
                <option value="BODEGA" <?= $tipo_filter === 'BODEGA' ? 'selected' : '' ?>>Bodega</option>
                <option value="RUTA" <?= $tipo_filter === 'RUTA' ? 'selected' : '' ?>>Ruta</option>
                <option value="PRODUCTOR" <?= $tipo_filter === 'PRODUCTOR' ? 'selected' : '' ?>>Productor</option>
            </select>
            <?php if ($hasProvCol('categoria')): ?>
            <select name="categoria" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary">
                <option value="">Todas las categorías</option>
                <?php foreach ($categoriasProveedor as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $categoria_filter === $cat ? 'selected' : '' ?>>
                    <?= htmlspecialchars($categoriaLabels[$cat] ?? $cat) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="estado" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary">
                <option value="">Todos los estados</option>
                <option value="1" <?= $estado_filter === '1' ? 'selected' : '' ?>>Activos</option>
                <option value="0" <?= $estado_filter === '0' ? 'selected' : '' ?>>Inactivos</option>
            </select>
            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </button>
            <?php if ($search || $tipo_filter || $categoria_filter || $estado_filter !== ''): ?>
            <a href="<?= APP_URL ?>/configuracion/proveedores.php" class="px-4 py-2 text-gray-600 hover:text-gray-800 transition-colors">
                Limpiar
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tabla de Proveedores -->
    <div class="bg-white rounded-xl shadow-sm border border-olive/20 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gradient-to-r from-primary to-primary/80 text-white">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Código</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Código ID</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Nombre</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Cédula/RUC</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Tipo</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Categoría</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Correo</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold">Teléfono</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold">Lotes</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold">Peso Total (kg)</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold">Estado</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($proveedores)): ?>
                    <tr>
                        <td colspan="12" class="px-6 py-12 text-center text-gray-500">
                            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            <p class="text-lg font-medium">No se encontraron proveedores</p>
                            <p class="text-sm">
                                <?php if ($categoriasActivasCount === 0): ?>
                                    Primero registre una categoría y luego cree proveedores.
                                <?php else: ?>
                                    Agregue un nuevo proveedor o modifique los filtros de búsqueda.
                                <?php endif; ?>
                            </p>
                            <?php if ($categoriasActivasCount === 0): ?>
                            <button type="button" onclick="openCategoriaModal('create')" class="mt-4 px-5 py-2 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors inline-flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h8m-8 5h8m-8 5h8M5 7h.01M5 12h.01M5 17h.01"/>
                                </svg>
                                Crear categoría
                            </button>
                            <?php else: ?>
                            <button type="button" onclick="openProveedorModal('create')" class="js-open-create-proveedor mt-4 px-5 py-2 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors inline-flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                </svg>
                                Crear proveedor
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($proveedores as $prov): ?>
                    <tr class="hover:bg-ivory/50 transition-colors <?= !$prov['activo'] ? 'opacity-60' : '' ?>">
                        <td class="px-6 py-4">
                            <span class="font-mono font-semibold text-primary"><?= htmlspecialchars($prov['codigo']) ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <span class="font-mono text-gray-700"><?= htmlspecialchars($prov['codigo_identificacion'] ?? '-') ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-medium text-gray-900"><?= htmlspecialchars($prov['nombre']) ?></div>
                            <?php if ($prov['direccion']): ?>
                            <div class="text-sm text-gray-500 truncate max-w-xs" title="<?= htmlspecialchars($prov['direccion']) ?>">
                                <?= htmlspecialchars($prov['direccion']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-gray-600">
                            <?= htmlspecialchars($prov['cedula_ruc'] ?? '-') ?>
                        </td>
                        <td class="px-6 py-4">
                            <?php
                            $tipoColors = [
                                'MERCADO' => 'bg-blue-100 text-blue-800',
                                'BODEGA' => 'bg-amber-100 text-amber-800',
                                'RUTA' => 'bg-purple-100 text-purple-800',
                                'PRODUCTOR' => 'bg-teal-100 text-teal-800'
                            ];
                            $color = $tipoColors[$prov['tipo']] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="px-3 py-1 rounded-full text-xs font-medium <?= $color ?>">
                                <?= htmlspecialchars($prov['tipo']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-gray-600">
                            <?php $categoriaProv = $prov['categoria'] ?? ''; ?>
                            <?= htmlspecialchars($categoriaProv !== '' ? ($categoriaLabels[$categoriaProv] ?? $categoriaProv) : '-') ?>
                        </td>
                        <td class="px-6 py-4 text-gray-600">
                            <?= htmlspecialchars($prov['email'] ?? '-') ?>
                        </td>
                        <td class="px-6 py-4 text-gray-600">
                            <?= htmlspecialchars($prov['telefono'] ?: '-') ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="font-semibold text-primary"><?= number_format($prov['total_lotes']) ?></span>
                        </td>
                        <td class="px-6 py-4 text-right font-medium">
                            <?= number_format($prov['peso_total'], 2) ?>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <button onclick="toggleEstado(<?= $prov['id'] ?>)" 
                                    class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium transition-colors
                                           <?= $prov['activo'] ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' : 'bg-red-100 text-red-800 hover:bg-red-200' ?>">
                                <span class="w-2 h-2 rounded-full <?= $prov['activo'] ? 'bg-emerald-500' : 'bg-red-500' ?>"></span>
                                <?= $prov['activo'] ? 'Activo' : 'Inactivo' ?>
                            </button>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center justify-center gap-2">
                                <button onclick="openProveedorModal('edit', <?= $prov['id'] ?>)" 
                                        class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Editar">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                                <?php if ($prov['total_lotes'] == 0): ?>
                                <button onclick="deleteProveedor(<?= $prov['id'] ?>, '<?= htmlspecialchars($prov['nombre']) ?>')" 
                                        class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Eliminar">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Info -->
    <div class="mt-6 text-sm text-gray-500 text-center">
        Mostrando <?= count($proveedores) ?> proveedor(es)
    </div>
</div>

<!-- Modal Crear/Editar -->
<div id="proveedorModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl transform transition-all">
        <div class="bg-gradient-to-r from-primary to-primary/80 px-6 py-4 rounded-t-2xl">
            <h3 id="modalTitle" class="text-xl font-bold text-white">Nuevo Proveedor</h3>
        </div>
        
        <form id="proveedorForm" class="p-6">
            <input type="hidden" id="proveedorId" name="id">
            <input type="hidden" id="formAction" name="action" value="create">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Código *</label>
                        <input type="text" id="codigo" name="codigo" required maxlength="20"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary uppercase"
                               placeholder="Ej: PROV001">
                    </div>
                    <?php if ($hasProvCol('codigo_identificacion')): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Código de identificación *</label>
                        <input type="text" id="codigo_identificacion" name="codigo_identificacion" maxlength="20"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary uppercase"
                               placeholder="Ej: PRO-00001">
                    </div>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nombre/Empresa *</label>
                        <input type="text" id="nombre" name="nombre" required maxlength="100"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"
                               placeholder="Nombre o razón social">
                    </div>
                    <?php if ($hasProvCol('cedula_ruc')): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cédula/RUC</label>
                        <input type="text" id="cedula_ruc" name="cedula_ruc" maxlength="20"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"
                               placeholder="10 o 13 dígitos">
                    </div>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
                        <select id="tipo" name="tipo" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                            <option value="MERCADO">Mercado</option>
                            <option value="BODEGA">Bodega</option>
                            <option value="RUTA">Ruta</option>
                            <option value="PRODUCTOR">Productor</option>
                        </select>
                    </div>
                    <?php if ($hasProvCol('categoria')): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Categoría proveedor *</label>
                        <select id="categoria" name="categoria" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"></select>
                        <p class="text-xs text-gray-500 mt-1">Seleccione una categoría registrada en el catálogo de tipos.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <?php if ($hasProvCol('email')): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Correo electrónico</label>
                        <input type="email" id="email" name="email" maxlength="120"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"
                               placeholder="proveedor@correo.com">
                    </div>
                    <?php endif; ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                        <input type="text" id="telefono" name="telefono" maxlength="50"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"
                               placeholder="Ej: 0999123456">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contacto (opcional)</label>
                    <input type="text" id="contacto" name="contacto" maxlength="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"
                           placeholder="Nombre de contacto">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Dirección</label>
                    <textarea id="direccion" name="direccion" rows="2"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"
                              placeholder="Dirección completa"></textarea>
                </div>
            </div>
            
            <div class="flex justify-end gap-3 mt-6 pt-6 border-t">
                <button type="button" onclick="closeProveedorModal()" 
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                    Cancelar
                </button>
                <button type="submit" 
                        class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors">
                    <span id="submitBtnText">Guardar</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Crear/Editar Categoría -->
<div id="categoriaModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl transform transition-all">
        <div class="bg-gradient-to-r from-primary to-primary/80 px-6 py-4 rounded-t-2xl">
            <h3 id="categoriaModalTitle" class="text-xl font-bold text-white">Nueva Categoría/Tipo</h3>
        </div>

        <form id="categoriaForm" class="p-6">
            <input type="hidden" id="categoriaId" name="id">
            <input type="hidden" id="categoriaAction" name="action" value="create_category">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Código categoría *</label>
                    <input type="text" id="categoriaCodigo" name="codigo" required maxlength="20"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary uppercase"
                           placeholder="Ej: ES, B, M, VP...">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre categoría *</label>
                    <input type="text" id="categoriaNombre" name="nombre" required maxlength="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"
                           placeholder="Ej: Esmeraldas, Bodega, Vía Pedernales">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
                    <select id="categoriaTipo" name="tipo" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                        <option value="MERCADO">Mercado</option>
                        <option value="BODEGA">Bodega</option>
                        <option value="RUTA">Ruta</option>
                        <option value="PRODUCTOR">Productor</option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-6 pt-6 border-t">
                <button type="button" onclick="closeCategoriaModal()"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                    Cancelar
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-primary text-white rounded-lg hover:bg-primary/90 transition-colors">
                    <span id="categoriaSubmitBtnText">Guardar</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const proveedoresUrl = '<?= APP_URL ?>/configuracion/proveedores.php';
const csrfToken = '<?= e($csrfToken) ?>';
const categoriasCatalogoData = <?= json_encode(array_map(static function (array $cat) use ($normalizarCategoria, $categoriaLabels) {
    $key = $normalizarCategoria((string)($cat['categoria'] ?? $cat['nombre'] ?? ''));
    return [
        'id' => (int)($cat['id'] ?? 0),
        'codigo' => (string)($cat['codigo'] ?? ''),
        'label' => (string)($cat['nombre'] ?? ($categoriaLabels[$key] ?? $key)),
        'tipo' => strtoupper((string)($cat['tipo'] ?? 'RUTA')),
        'key' => $key,
        'activo' => (int)($cat['activo'] ?? 1),
    ];
}, $categoriasCatalogo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const categoriasPorTipo = <?= json_encode($categoriasPorTipo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const categoriasDisponibles = <?= json_encode($categoriasProveedor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const tipoPorCategoria = <?= json_encode($tipoPorCategoria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const categoriaLabels = <?= json_encode($categoriaLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function sincronizarTipoDesdeCategoria() {
    const categoriaSelect = document.getElementById('categoria');
    const tipoSelect = document.getElementById('tipo');
    if (!categoriaSelect || !tipoSelect) return;

    const categoria = categoriaSelect.value || '';
    let tipoDetectado = tipoPorCategoria[categoria] || tipoSelect.value || 'MERCADO';
    const selectedIndex = categoriaSelect.selectedIndex;
    if (selectedIndex >= 0) {
        const selectedOption = categoriaSelect.options[selectedIndex];
        if (selectedOption && selectedOption.dataset && selectedOption.dataset.tipo) {
            tipoDetectado = selectedOption.dataset.tipo;
        }
    }
    tipoSelect.value = tipoDetectado;
}

function obtenerCategoriasFormulario(permitirInactivas = false) {
    if (categoriasCatalogoData && categoriasCatalogoData.length > 0) {
        return categoriasCatalogoData.filter((cat) => permitirInactivas || Number(cat.activo || 0) === 1);
    }
    return categoriasDisponibles.map((key) => ({
        key,
        label: categoriaLabels[key] || key,
        tipo: tipoPorCategoria[key] || 'RUTA',
        activo: 1
    }));
}

function actualizarCategorias(categoriaSeleccionada = '', permitirInactivas = false) {
    const categoriaSelect = document.getElementById('categoria');
    if (!categoriaSelect) return;

    categoriaSelect.innerHTML = '';
    const categorias = obtenerCategoriasFormulario(permitirInactivas);

    categorias.forEach((cat) => {
        const option = document.createElement('option');
        option.value = cat.key;
        option.dataset.tipo = cat.tipo || (tipoPorCategoria[cat.key] || 'RUTA');
        option.textContent = cat.label || categoriaLabels[cat.key] || cat.key;
        if (categoriaSeleccionada && categoriaSeleccionada === cat.key) {
            option.selected = true;
        }
        categoriaSelect.appendChild(option);
    });

    if (!categoriaSeleccionada && categorias.length > 0) {
        categoriaSelect.value = categorias[0].key;
    }
    const keys = categorias.map((cat) => cat.key);
    if (categoriaSeleccionada && keys.includes(categoriaSeleccionada)) {
        categoriaSelect.value = categoriaSeleccionada;
    }

    sincronizarTipoDesdeCategoria();
}

// Funciones del Modal
function openProveedorModal(mode, id = null, categoriaPreset = '') {
    const modal = document.getElementById('proveedorModal');
    const form = document.getElementById('proveedorForm');
    const title = document.getElementById('modalTitle');
    const action = document.getElementById('formAction');
    const submitBtn = document.getElementById('submitBtnText');
    
    form.reset();
    
    if (mode === 'create') {
        title.textContent = 'Nuevo Proveedor';
        action.value = 'create';
        submitBtn.textContent = 'Crear Proveedor';
        document.getElementById('proveedorId').value = '';
        actualizarCategorias(categoriaPreset || '', false);
        if (document.getElementById('categoria') && document.getElementById('categoria').options.length === 0) {
            showNotification('Primero cree una categoría/tipo para poder registrar proveedores.', 'error');
            return;
        }
    } else if (mode === 'edit' && id) {
        title.textContent = 'Editar Proveedor';
        action.value = 'update';
        submitBtn.textContent = 'Guardar Cambios';
        
        // Cargar datos
        fetch(proveedoresUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get&id=${id}&_csrf=${encodeURIComponent(csrfToken)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const p = data.data;
                if (Number(p.es_categoria || 0) === 1) {
                    closeProveedorModal();
                    openCategoriaModal('edit', p.id);
                    return;
                }
                document.getElementById('proveedorId').value = p.id;
                document.getElementById('codigo').value = p.codigo;
                document.getElementById('nombre').value = p.nombre;
                document.getElementById('tipo').value = p.tipo;
                document.getElementById('contacto').value = p.contacto || '';
                document.getElementById('telefono').value = p.telefono || '';
                document.getElementById('direccion').value = p.direccion || '';
                const codigoIdInput = document.getElementById('codigo_identificacion');
                if (codigoIdInput) codigoIdInput.value = p.codigo_identificacion || '';
                const cedulaRucInput = document.getElementById('cedula_ruc');
                if (cedulaRucInput) cedulaRucInput.value = p.cedula_ruc || '';
                const emailInput = document.getElementById('email');
                if (emailInput) emailInput.value = p.email || '';
                let categoriaResuelta = p.categoria || '';
                if (!categoriaResuelta) {
                    const categoriasTipo = categoriasPorTipo[p.tipo] || [];
                    categoriaResuelta = categoriasTipo.length > 0 ? categoriasTipo[0] : '';
                }
                actualizarCategorias(categoriaResuelta, true);
            } else {
                showNotification(data.message, 'error');
                return;
            }
        });
    }
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeProveedorModal() {
    const modal = document.getElementById('proveedorModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function openCategoriaModal(mode, id = null) {
    const modal = document.getElementById('categoriaModal');
    const form = document.getElementById('categoriaForm');
    const title = document.getElementById('categoriaModalTitle');
    const action = document.getElementById('categoriaAction');
    const submitBtn = document.getElementById('categoriaSubmitBtnText');

    form.reset();
    document.getElementById('categoriaId').value = '';

    if (mode === 'create') {
        title.textContent = 'Nueva Categoría/Tipo';
        action.value = 'create_category';
        submitBtn.textContent = 'Crear categoría';
    } else if (mode === 'edit' && id) {
        title.textContent = 'Editar Categoría/Tipo';
        action.value = 'update_category';
        submitBtn.textContent = 'Guardar cambios';

        fetch(proveedoresUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get&id=${id}&_csrf=${encodeURIComponent(csrfToken)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const c = data.data;
                if (Number(c.es_categoria || 0) !== 1) {
                    showNotification('El registro seleccionado no es una categoría', 'error');
                    return;
                }
                document.getElementById('categoriaId').value = c.id;
                document.getElementById('categoriaCodigo').value = c.codigo || '';
                document.getElementById('categoriaNombre').value = c.nombre || '';
                document.getElementById('categoriaTipo').value = c.tipo || 'RUTA';
            } else {
                showNotification(data.message, 'error');
            }
        });
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeCategoriaModal() {
    const modal = document.getElementById('categoriaModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Envío del formulario
document.getElementById('proveedorForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch(proveedoresUrl, {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            closeProveedorModal();
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(err => {
        showNotification('Error de conexión', 'error');
    });
});

document.getElementById('categoriaForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);

    fetch(proveedoresUrl, {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            closeCategoriaModal();
            setTimeout(() => location.reload(), 800);
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(() => {
        showNotification('Error de conexión', 'error');
    });
});

// Toggle estado
function toggleEstado(id) {
    if (!confirm('¿Cambiar el estado de este proveedor?')) return;
    
    fetch(proveedoresUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=toggle&id=${id}&_csrf=${encodeURIComponent(csrfToken)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            showNotification(data.message, 'error');
        }
    });
}

// Eliminar proveedor
function deleteProveedor(id, nombre) {
    if (!confirm(`¿Está seguro de eliminar el proveedor "${nombre}"?\n\nEsta acción no se puede deshacer.`)) return;
    
    fetch(proveedoresUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete&id=${id}&_csrf=${encodeURIComponent(csrfToken)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            showNotification(data.message, 'error');
        }
    });
}

// Notificaciones
function showNotification(message, type = 'info') {
    const colors = {
        success: 'bg-emerald-500',
        error: 'bg-red-500',
        info: 'bg-blue-500'
    };
    
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 ${colors[type]} text-white px-6 py-3 rounded-lg shadow-lg z-50 transform transition-all duration-300`;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.classList.add('opacity-0', 'translate-x-full');
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Cerrar modal con ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeProveedorModal();
        closeCategoriaModal();
    }
});

// Cerrar modal al hacer click fuera
document.getElementById('proveedorModal').addEventListener('click', function(e) {
    if (e.target === this) closeProveedorModal();
});
document.getElementById('categoriaModal').addEventListener('click', function(e) {
    if (e.target === this) closeCategoriaModal();
});

window.openProveedorModal = openProveedorModal;
window.closeProveedorModal = closeProveedorModal;
window.openCategoriaModal = openCategoriaModal;
window.closeCategoriaModal = closeCategoriaModal;
window.toggleEstado = toggleEstado;
window.deleteProveedor = deleteProveedor;

document.addEventListener('DOMContentLoaded', function() {
    const tipoSelect = document.getElementById('tipo');
    if (tipoSelect) {
        tipoSelect.addEventListener('change', function() {
            const categoriaSelect = document.getElementById('categoria');
            if (!categoriaSelect) return;
            const candidatas = categoriasPorTipo[this.value] || [];
            const opcionesDisponibles = Array.from(categoriaSelect.options).map((opt) => opt.value);
            if (!opcionesDisponibles.includes(categoriaSelect.value)) {
                const candidataValida = candidatas.find((cat) => opcionesDisponibles.includes(cat));
                if (candidataValida) {
                    categoriaSelect.value = candidataValida;
                } else if (opcionesDisponibles.length > 0) {
                    categoriaSelect.value = opcionesDisponibles[0];
                }
            }
            sincronizarTipoDesdeCategoria();
        });
        if (document.getElementById('categoria')) {
            document.getElementById('categoria').addEventListener('change', sincronizarTipoDesdeCategoria);
        }
        actualizarCategorias();
    }

    const codigoInput = document.getElementById('codigo');
    if (codigoInput) {
        codigoInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }
    const codigoIdentInput = document.getElementById('codigo_identificacion');
    if (codigoIdentInput) {
        codigoIdentInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }
    const categoriaCodigoInput = document.getElementById('categoriaCodigo');
    if (categoriaCodigoInput) {
        categoriaCodigoInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layouts/main.php';
?>
