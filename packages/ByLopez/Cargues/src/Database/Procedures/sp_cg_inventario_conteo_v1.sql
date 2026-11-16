-- =====================================================================================================================
-- sp_cg_inventario_conteo_v1 — Tipo de cargue «Inventario — Actualizar cantidades (conteo físico)» (versión 1).
--
-- Contrato del motor (07 §8): firma fija, lee SOLO su carga (cg_carga_id = p_carga_id) de la intermedia
-- `bylopez_cgi_inventario_conteo`, modelo transaccional «motor», SIN COMMIT/ROLLBACK/DDL, SQL SECURITY INVOKER.
--
-- ESTE PROCEDIMIENTO SOLO CLASIFICA. No escribe stock ni movimientos: lo hace la EXTENSIÓN del tipo (InventarioConteoExtension) por el libro
-- de inventario de CorteConfección (stock físico + índice vendible + movimiento auditado), una transacción por producto. NO CREA productos.
-- El cruce del archivo con el catálogo (por ID o SKU), el stock vigente y la diferencia los calcula la extensión al preparar la carga y
-- quedan en los campos DERIVADOS; aquí se usan como hechos.
--
-- Reglas (sobre filas con cg_estado IS NULL):
--   -- solo EJECUTAR (VALIDAR solo puede marcar «rechazada») --
--   R1 fila ignorada (producto configurable: su stock vive en las variantes) ... sin_cambio / CONFIGURABLE_IGNORADO
--   -- VALIDAR y EJECUTAR --
--   R2 fila bloqueada en el análisis (no se identifica, no existe, ID y SKU contradictorios, repetida, sin cantidad…) ... rechazada
--   R3 fila sin análisis (no debería pasar) ..................................... rechazada / SIN_ANALISIS
--   -- solo EJECUTAR --
--   R4 la cantidad del archivo ya es la del sistema ............................. sin_cambio / YA_EN_CANTIDAD
--   R5 el resto queda PLANEADO: cg_detalle = 'actualizar'. La extensión aplica y marca (aplicada | sin_cambio | rechazada).
-- Acción única: 'actualizar_cantidades'.
-- =====================================================================================================================
DROP PROCEDURE IF EXISTS sp_cg_inventario_conteo_v1;
DELIMITER $$
CREATE PROCEDURE sp_cg_inventario_conteo_v1(
    IN  p_carga_id  BIGINT,
    IN  p_accion    VARCHAR(40),
    IN  p_modo      VARCHAR(10),
    OUT p_resultado VARCHAR(10),
    OUT p_mensaje   VARCHAR(500)
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_rechazadas INT DEFAULT 0;
    DECLARE v_planeadas INT DEFAULT 0;

    SET p_resultado = 'OK';
    SET p_mensaje = NULL;

    IF p_accion NOT IN ('actualizar_cantidades') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Acción no soportada por sp_cg_inventario_conteo_v1';
    END IF;

    IF p_modo NOT IN ('VALIDAR', 'EJECUTAR') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Modo no soportado';
    END IF;

    -- R1 · configurables y otras filas que se ignoran a propósito (no son error)
    UPDATE bylopez_cgi_inventario_conteo i
       SET i.cg_estado = 'sin_cambio', i.cg_codigo = 'CONFIGURABLE_IGNORADO', i.cg_detalle = 'ignorada',
           i.cg_mensaje = 'Producto configurable: su cantidad es la suma de sus variantes y no se carga. No se modificó nada.',
           i.cg_procesado_en = NOW()
     WHERE p_modo = 'EJECUTAR' AND i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND i.fila_plan = 'ignorar';

    -- R2 · fila bloqueada
    UPDATE bylopez_cgi_inventario_conteo i
       SET i.cg_estado = 'rechazada', i.cg_codigo = i.bloqueo_codigo, i.cg_mensaje = i.bloqueo_mensaje,
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND i.bloqueo_codigo IS NOT NULL;

    -- R3 · defensa: sin plan del análisis no se aplica nada
    UPDATE bylopez_cgi_inventario_conteo i
       SET i.cg_estado = 'rechazada', i.cg_codigo = 'SIN_ANALISIS',
           i.cg_mensaje = 'La fila no tiene el análisis del producto (prepare la carga de nuevo). No se aplicó nada.',
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND (i.fila_plan IS NULL OR i.producto_id IS NULL);

    -- R4 · ya está en esa cantidad
    UPDATE bylopez_cgi_inventario_conteo i
       SET i.cg_estado = 'sin_cambio', i.cg_codigo = 'YA_EN_CANTIDAD', i.cg_detalle = 'ya_en_cantidad',
           i.cg_mensaje = 'El sistema ya tiene esa cantidad. No se modificó nada.',
           i.cg_procesado_en = NOW()
     WHERE p_modo = 'EJECUTAR' AND i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND i.fila_plan = 'sin_cambio';

    SELECT COUNT(*) INTO v_rechazadas FROM bylopez_cgi_inventario_conteo WHERE cg_carga_id = p_carga_id AND cg_estado = 'rechazada';

    IF p_modo = 'EJECUTAR' THEN
        -- R5 · plan para la extensión (sin marca final)
        UPDATE bylopez_cgi_inventario_conteo i
           SET i.cg_detalle = 'actualizar'
         WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL;
        SET v_planeadas = ROW_COUNT();
        SET p_mensaje = CONCAT(v_planeadas, ' fila(s) planeada(s) para aplicar');
    END IF;

    IF v_rechazadas > 0 THEN SET p_resultado = 'PARCIAL'; END IF;
END$$
DELIMITER ;
