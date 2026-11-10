-- =====================================================================================================================
-- sp_cg_productos_camisetas_v1 — Tipo de cargue «Productos — Camisetas piel de durazno» (versión 1). Documentación: docs/17.
--
-- Contrato del motor (07 §8): firma fija, lee SOLO su carga (cg_carga_id = p_carga_id) de la intermedia
-- `bylopez_cgi_productos_camisetas`, modelo transaccional «motor», SIN COMMIT/ROLLBACK/DDL, SQL SECURITY INVOKER.
--
-- ESTE PROCEDIMIENTO SOLO CLASIFICA. No escribe productos, atributos, categorías, precios ni inventario: escribe únicamente las marcas
-- de la intermedia. Crear un producto de Bagisto exige sus repositorios y eventos (product_flat, índices de inventario, precio y
-- búsqueda, URL, caché del catálogo), que viven en PHP: lo hace la EXTENSIÓN del tipo (ProductosCamisetasExtension), una transacción
-- por producto base. El análisis por grupo (opciones del catálogo, producto existente, conflictos) lo calcula esa extensión al
-- preparar la carga y queda en los campos DERIVADOS; aquí se usan como hechos.
--
-- Reglas (sobre filas con cg_estado IS NULL):
--   R1 el grupo (producto base) quedó bloqueado en el análisis ......... bloqueo_codigo (motivo en bloqueo_mensaje)
--   R2 fila sin análisis (no debería pasar) ............................. SIN_ANALISIS
--   -- solo EJECUTAR --
--   R3 el resto queda PLANEADO: cg_detalle = fila_plan ('crear_variante' | 'sin_cambio'). La extensión aplica cada grupo y pone la
--      marca final de sus filas (aplicada | sin_cambio | rechazada).
-- Acciones: 'crear_inactivos' (por defecto) | 'crear_activos' — la diferencia la aplica la extensión (estado de los productos nuevos).
-- =====================================================================================================================
DROP PROCEDURE IF EXISTS sp_cg_productos_camisetas_v1;
DELIMITER $$
CREATE PROCEDURE sp_cg_productos_camisetas_v1(
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

    IF p_accion NOT IN ('crear_inactivos', 'crear_activos') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Acción no soportada por sp_cg_productos_camisetas_v1';
    END IF;

    IF p_modo NOT IN ('VALIDAR', 'EJECUTAR') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Modo no soportado';
    END IF;

    -- R1 · grupo bloqueado (todas sus filas: un producto no se crea incompleto)
    UPDATE bylopez_cgi_productos_camisetas i
       SET i.cg_estado = 'rechazada', i.cg_codigo = i.bloqueo_codigo, i.cg_mensaje = i.bloqueo_mensaje,
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND i.bloqueo_codigo IS NOT NULL;

    -- R2 · defensa: sin plan del análisis no se aplica nada
    UPDATE bylopez_cgi_productos_camisetas i
       SET i.cg_estado = 'rechazada', i.cg_codigo = 'SIN_ANALISIS',
           i.cg_mensaje = 'La fila no tiene el análisis del producto (prepare la carga de nuevo). No se aplicó nada.',
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND (i.fila_plan IS NULL OR i.grupo_clave IS NULL);

    SELECT COUNT(*) INTO v_rechazadas FROM bylopez_cgi_productos_camisetas WHERE cg_carga_id = p_carga_id AND cg_estado = 'rechazada';

    IF p_modo = 'EJECUTAR' THEN
        -- R3 · plan para la extensión (sin marca final)
        UPDATE bylopez_cgi_productos_camisetas i
           SET i.cg_detalle = i.fila_plan
         WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL;
        SET v_planeadas = ROW_COUNT();
        SET p_mensaje = CONCAT(v_planeadas, ' fila(s) planeada(s) para aplicar');
    END IF;

    IF v_rechazadas > 0 THEN SET p_resultado = 'PARCIAL'; END IF;
END$$
DELIMITER ;
