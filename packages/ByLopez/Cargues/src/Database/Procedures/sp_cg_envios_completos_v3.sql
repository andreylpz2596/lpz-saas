-- =====================================================================================================================
-- sp_cg_envios_completos_v3 — Tipo de cargue «99 Envíos — Envíos completos» (versión 3). Documentación: docs/13 y docs/16.
--
-- Cambio frente a v2 (docs/16 §15): las reglas R1–R6 y la escritura del ESTADO REPORTADO (R8a–R8c) son IDÉNTICAS. Lo nuevo es
-- que las filas cuyo estado literal es «Entregada» o «Devolución ratificada» y que son el reporte VIGENTE de su guía (aplicadas
-- ahora por R8, o ya registradas con la misma fecha por R7) NO se marcan aquí: quedan PLANEADAS (cg_estado NULL con cg_detalle
-- 'insertada' | 'actualizada' | 'ya_registrado') para la EXTENSIÓN del tipo (EnviosCompletosHitosExtension), que registra el hito
-- logístico por los servicios de Logística (entrega + validación, o retorno) y pone la marca final. Que el reporte no cambie
-- («ya_registrado») NO impide completar un hito pendiente (p. ej. la validación que falló la primera vez).
-- Un reporte ANTIGUO (R6) o rechazado nunca genera hitos. Los demás estados se marcan aquí como en v2 (aplicada / sin_cambio).
--
-- Contrato del motor (07 §8): firma fija, lee SOLO su carga (cg_carga_id = p_carga_id) de la intermedia
-- `bylopez_cgi_envios_completos`, modelo transaccional «motor» (SIN COMMIT/ROLLBACK/DDL: el motor abre y confirma la
-- transacción), SQL SECURITY INVOKER (VALIDAR corre con la cuenta restringida; ver docs/12). Con extensión, el contrato admite
-- filas sin marca si tienen plan (cg_detalle).
--
-- 99 Envíos es un INTERMEDIARIO, no la transportadora física. Proveedor fijo '99envios'; la transportadora EFECTIVA sale
-- de la configuración `bylopez_lg_proveedor_transportadoras` (código o nombre del proveedor → catálogo interno). Se cruza por
-- (transportadora efectiva, número de guía) con igualdad EXACTA; nunca por nombre, teléfono ni pedido.
--
-- Escribe SOLO: el estado reportado por el proveedor (actual + historial) y las marcas/planes de la intermedia. Las entregas,
-- validaciones y retornos los registra la extensión por los servicios de Logística; este procedimiento no los toca, ni la guía,
-- recepciones, recaudos, Cartera o comisiones, y no crea pedidos ni despachos.
--
-- Reglas (en este orden, sobre filas con cg_estado IS NULL):
--   R1 código o nombre de transportadora sin equivalencia activa ................... rechazada TRANSPORTADORA_SIN_EQUIVALENCIA
--   R1b más de una equivalencia activa coincide (código y nombre de filas distintas) .. rechazada TRANSPORTADORA_AMBIGUA
--   R2 guía inexistente con esa transportadora (distingue si existe con otra) ...... rechazada GUIA_NO_REGISTRADA / GUIA_CON_OTRA_TRANSPORTADORA
--   R3 más de una guía coincide (defensivo: hay UNIQUE) ............................. rechazada GUIA_AMBIGUA
--   R3b dos filas de la carga llevan a la misma guía ................................ rechazada GUIA_REPETIDA_EN_CARGA (ambas)
--   R4 la guía ya tiene reportes con OTRA fecha_envio (posible guía reutilizada) .... rechazada GUIA_FECHA_ENVIO_DISTINTA
--   R5 misma fecha_actualizacion que la registrada pero otro estado u otros valores . rechazada CONFLICTO_MISMA_FECHA
--   -- solo EJECUTAR --
--   R6 fecha_actualizacion ANTERIOR a la registrada ................................. omitida_antigua (no se aplica)
--   R7 misma fecha_actualizacion, mismo estado y mismos valores ..................... sin_cambio (con hito: PLANEADA ya_registrado)
--   R8 el resto ................................................................... aplicada (insertada | actualizada) (con hito: PLANEADA)
--      Conciliación del recaudo: reportado = valor_comercial si aplica_contrapago = 1, 0 si = 0; sin dato si falta.
--      Interno = bylopez_lg_guias.recaudo_esperado de ESA guía. Diferencia exacta (DECIMAL, sin tolerancia). Si difiere,
--      la fila se APLICA igual (decisión de Camilo, 2026-10-15) y queda con la observación DISCREPANCIA_RECAUDO; si no
--      se puede conciliar (falta aplica_contrapago o valor_comercial), con la observación RECAUDO_SIN_DATO.
--      valor_servicio se REGISTRA literal, sin conciliar (pendiente: su equivalencia interna no está confirmada).
-- =====================================================================================================================
DROP PROCEDURE IF EXISTS sp_cg_envios_completos_v3;
DELIMITER $$
CREATE PROCEDURE sp_cg_envios_completos_v3(
    IN  p_carga_id  BIGINT,
    IN  p_accion    VARCHAR(40),
    IN  p_modo      VARCHAR(10),
    OUT p_resultado VARCHAR(10),
    OUT p_mensaje   VARCHAR(500)
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_rechazadas INT DEFAULT 0;
    DECLARE v_aplicadas INT DEFAULT 0;
    DECLARE v_planeadas INT DEFAULT 0;

    SET p_resultado = 'OK';
    SET p_mensaje = NULL;

    IF p_accion <> 'actualizar_estado' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Acción no soportada por sp_cg_envios_completos_v3';
    END IF;

    IF p_modo NOT IN ('VALIDAR', 'EJECUTAR') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Modo no soportado';
    END IF;

    -- R1 · código de transportadora del proveedor sin equivalencia activa.
    UPDATE bylopez_cgi_envios_completos i
    LEFT JOIN bylopez_lg_proveedor_transportadoras m
           ON m.proveedor = '99envios' AND m.activo = 1
          AND ((m.codigo_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.codigo_externo = BINARY i.transportadora)
            OR (m.nombre_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.nombre_externo = BINARY i.transportadora))
       SET i.cg_estado = 'rechazada', i.cg_codigo = 'TRANSPORTADORA_SIN_EQUIVALENCIA',
           i.cg_mensaje = CONCAT('El código o nombre de transportadora «', i.transportadora, '» de 99 Envíos no tiene equivalencia activa en el catálogo interno (Logística › proveedor_transportadoras).'),
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND m.id IS NULL;

    -- R1b · más de una equivalencia activa coincide con el valor (p. ej. un código de una fila igual al nombre de otra): se
    -- rechaza en lugar de cruzar con una elegida al azar (y de duplicar la fila en los cruces siguientes).
    UPDATE bylopez_cgi_envios_completos i
      JOIN (SELECT i2.cg_id, COUNT(*) AS n
              FROM bylopez_cgi_envios_completos i2
              JOIN bylopez_lg_proveedor_transportadoras m2
                   ON m2.proveedor = '99envios' AND m2.activo = 1
                  AND ((m2.codigo_externo = i2.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m2.codigo_externo = BINARY i2.transportadora)
                    OR (m2.nombre_externo = i2.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m2.nombre_externo = BINARY i2.transportadora))
             WHERE i2.cg_carga_id = p_carga_id AND i2.cg_estado IS NULL
             GROUP BY i2.cg_id HAVING COUNT(*) > 1) x ON x.cg_id = i.cg_id
       SET i.cg_estado = 'rechazada', i.cg_codigo = 'TRANSPORTADORA_AMBIGUA',
           i.cg_mensaje = CONCAT('«', i.transportadora, '» coincide con ', x.n, ' equivalencias activas de 99 Envíos: no se elige ninguna. Corrija la configuración (Logística › proveedor_transportadoras).'),
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL;

    -- R2 · la guía no existe con la transportadora efectiva. Si existe con OTRA, se dice cuál (p. ej. el proveedor
    -- registrado como transportadora), pero NO se cruza con ella.
    UPDATE bylopez_cgi_envios_completos i
      JOIN bylopez_lg_proveedor_transportadoras m
           ON m.proveedor = '99envios' AND m.activo = 1
          AND ((m.codigo_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.codigo_externo = BINARY i.transportadora)
            OR (m.nombre_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.nombre_externo = BINARY i.transportadora))
    LEFT JOIN bylopez_lg_guias g
           ON g.transportadora_codigo = m.transportadora_codigo
          AND g.numero_guia = i.numero_de_guia COLLATE utf8mb4_unicode_ci
          AND BINARY g.numero_guia = BINARY i.numero_de_guia
       SET i.cg_estado = 'rechazada',
           i.cg_codigo = IF((SELECT COUNT(*) FROM bylopez_lg_guias g2
                              WHERE g2.numero_guia = i.numero_de_guia COLLATE utf8mb4_unicode_ci
                                AND BINARY g2.numero_guia = BINARY i.numero_de_guia) > 0,
                            'GUIA_CON_OTRA_TRANSPORTADORA', 'GUIA_NO_REGISTRADA'),
           i.cg_mensaje = IF((SELECT COUNT(*) FROM bylopez_lg_guias g2
                               WHERE g2.numero_guia = i.numero_de_guia COLLATE utf8mb4_unicode_ci
                                 AND BINARY g2.numero_guia = BINARY i.numero_de_guia) > 0,
                             CONCAT('La guía existe en Logística con otra transportadora (',
                                    (SELECT GROUP_CONCAT(DISTINCT g3.transportadora_codigo ORDER BY g3.transportadora_codigo SEPARATOR ', ')
                                       FROM bylopez_lg_guias g3
                                      WHERE g3.numero_guia = i.numero_de_guia COLLATE utf8mb4_unicode_ci
                                        AND BINARY g3.numero_guia = BINARY i.numero_de_guia),
                                    ') y no con ', m.transportadora_codigo, '. No se cruza: corrija la guía en Logística si corresponde.'),
                             CONCAT('No hay guía ', m.transportadora_codigo, ' con ese número en Logística. No se crean pedidos ni despachos.')),
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND g.id IS NULL;

    -- R3 · más de una guía coincide (no debería ocurrir: UNIQUE (transportadora_codigo, numero_guia)).
    UPDATE bylopez_cgi_envios_completos i
      JOIN (SELECT i2.cg_id, COUNT(g.id) AS n
              FROM bylopez_cgi_envios_completos i2
              JOIN bylopez_lg_proveedor_transportadoras m
                   ON m.proveedor = '99envios' AND m.activo = 1
                  AND ((m.codigo_externo = i2.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.codigo_externo = BINARY i2.transportadora)
                    OR (m.nombre_externo = i2.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.nombre_externo = BINARY i2.transportadora))
              JOIN bylopez_lg_guias g
                   ON g.transportadora_codigo = m.transportadora_codigo
                  AND g.numero_guia = i2.numero_de_guia COLLATE utf8mb4_unicode_ci
                  AND BINARY g.numero_guia = BINARY i2.numero_de_guia
             WHERE i2.cg_carga_id = p_carga_id AND i2.cg_estado IS NULL
             GROUP BY i2.cg_id HAVING COUNT(g.id) > 1) x ON x.cg_id = i.cg_id
       SET i.cg_estado = 'rechazada', i.cg_codigo = 'GUIA_AMBIGUA',
           i.cg_mensaje = CONCAT(x.n, ' guías coinciden con la transportadora y el número: no se elige ninguna.'),
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL;

    -- R3b · dos filas de ESTA carga llevan a la misma guía (p. ej. dos códigos del proveedor con la misma equivalencia):
    -- se rechazan ambas en lugar de dejar que una pise a la otra según el orden.
    UPDATE bylopez_cgi_envios_completos i
      JOIN (SELECT i2.cg_id
              FROM bylopez_cgi_envios_completos i2
              JOIN bylopez_lg_proveedor_transportadoras m
                   ON m.proveedor = '99envios' AND m.activo = 1
                  AND ((m.codigo_externo = i2.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.codigo_externo = BINARY i2.transportadora)
                    OR (m.nombre_externo = i2.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.nombre_externo = BINARY i2.transportadora))
              JOIN bylopez_lg_guias g
                   ON g.transportadora_codigo = m.transportadora_codigo
                  AND g.numero_guia = i2.numero_de_guia COLLATE utf8mb4_unicode_ci
                  AND BINARY g.numero_guia = BINARY i2.numero_de_guia
              JOIN (SELECT g4.id AS guia_id
                      FROM bylopez_cgi_envios_completos i4
                      JOIN bylopez_lg_proveedor_transportadoras m4
                           ON m4.proveedor = '99envios' AND m4.activo = 1
                          AND ((m4.codigo_externo = i4.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m4.codigo_externo = BINARY i4.transportadora)
                            OR (m4.nombre_externo = i4.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m4.nombre_externo = BINARY i4.transportadora))
                      JOIN bylopez_lg_guias g4
                           ON g4.transportadora_codigo = m4.transportadora_codigo
                          AND g4.numero_guia = i4.numero_de_guia COLLATE utf8mb4_unicode_ci
                          AND BINARY g4.numero_guia = BINARY i4.numero_de_guia
                     WHERE i4.cg_carga_id = p_carga_id AND (i4.cg_estado IS NULL OR i4.cg_estado = 'rechazada' AND i4.cg_codigo = 'GUIA_REPETIDA_EN_CARGA')
                     GROUP BY g4.id HAVING COUNT(*) > 1) rep ON rep.guia_id = g.id
             WHERE i2.cg_carga_id = p_carga_id AND i2.cg_estado IS NULL
             GROUP BY i2.cg_id) x ON x.cg_id = i.cg_id
       SET i.cg_estado = 'rechazada', i.cg_codigo = 'GUIA_REPETIDA_EN_CARGA',
           i.cg_mensaje = 'Otra fila de este archivo corresponde a la misma guía: no se aplica ninguna de las dos.',
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL;

    -- R4 · la guía ya tiene reportes de 99 Envíos con OTRA fecha de envío: posible número reutilizado. No se actualiza.
    UPDATE bylopez_cgi_envios_completos i
      JOIN bylopez_lg_proveedor_transportadoras m
           ON m.proveedor = '99envios' AND m.activo = 1
          AND ((m.codigo_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.codigo_externo = BINARY i.transportadora)
            OR (m.nombre_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.nombre_externo = BINARY i.transportadora))
      JOIN bylopez_lg_guias g
           ON g.transportadora_codigo = m.transportadora_codigo
          AND g.numero_guia = i.numero_de_guia COLLATE utf8mb4_unicode_ci
          AND BINARY g.numero_guia = BINARY i.numero_de_guia
      JOIN bylopez_lg_guia_estados_proveedor e ON e.guia_id = g.id AND e.proveedor = '99envios'
       SET i.cg_estado = 'rechazada', i.cg_codigo = 'GUIA_FECHA_ENVIO_DISTINTA',
           i.cg_mensaje = CONCAT('La guía ya fue reportada con fecha de envío ', DATE_FORMAT(e.fecha_envio_proveedor, '%Y-%m-%d %H:%i:%s'),
                                 ' y ahora llega con ', DATE_FORMAT(i.fecha_envio, '%Y-%m-%d %H:%i:%s'), ': posible guía reutilizada. No se actualiza.'),
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND e.fecha_envio_proveedor <> i.fecha_envio;

    -- R5 · misma fecha de actualización que la registrada pero otro estado u otros valores: no se elige ganador.
    UPDATE bylopez_cgi_envios_completos i
      JOIN bylopez_lg_proveedor_transportadoras m
           ON m.proveedor = '99envios' AND m.activo = 1
          AND ((m.codigo_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.codigo_externo = BINARY i.transportadora)
            OR (m.nombre_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.nombre_externo = BINARY i.transportadora))
      JOIN bylopez_lg_guias g
           ON g.transportadora_codigo = m.transportadora_codigo
          AND g.numero_guia = i.numero_de_guia COLLATE utf8mb4_unicode_ci
          AND BINARY g.numero_guia = BINARY i.numero_de_guia
      JOIN bylopez_lg_guia_estados_proveedor e ON e.guia_id = g.id AND e.proveedor = '99envios'
       SET i.cg_estado = 'rechazada', i.cg_codigo = 'CONFLICTO_MISMA_FECHA',
           i.cg_mensaje = CONCAT('Con la misma fecha de actualización (', DATE_FORMAT(e.fecha_estado_proveedor, '%Y-%m-%d %H:%i:%s'),
                                 ') ya se registró «', e.estado_proveedor, '» con otros datos. No se elige ganador.'),
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL
       AND e.fecha_estado_proveedor = i.fecha_actualizacion
       AND NOT (BINARY e.estado_proveedor = BINARY i.estado_del_envio
                AND e.valor_comercial_reportado <=> i.valor_comercial
                AND BINARY e.valor_servicio_reportado <=> BINARY i.valor_servicio
                AND e.valor_seguro_reportado <=> i.valor_seguro_99
                AND BINARY e.seguro_reportado <=> BINARY i.seguro
                AND e.aplica_contrapago_reportado <=> i.aplica_contrapago);

    IF p_modo = 'VALIDAR' THEN
        -- VALIDAR solo marca «rechazada» (contrato): no clasifica antiguas ni aplica nada.
        SELECT COUNT(*) INTO v_rechazadas FROM bylopez_cgi_envios_completos WHERE cg_carga_id = p_carga_id AND cg_estado = 'rechazada';
        IF v_rechazadas > 0 THEN SET p_resultado = 'PARCIAL'; END IF;
    ELSE
        -- R6 · información antigua: la fecha de actualización es ANTERIOR a la registrada.
        UPDATE bylopez_cgi_envios_completos i
          JOIN bylopez_lg_proveedor_transportadoras m
               ON m.proveedor = '99envios' AND m.activo = 1
              AND ((m.codigo_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.codigo_externo = BINARY i.transportadora)
                OR (m.nombre_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.nombre_externo = BINARY i.transportadora))
          JOIN bylopez_lg_guias g
               ON g.transportadora_codigo = m.transportadora_codigo
              AND g.numero_guia = i.numero_de_guia COLLATE utf8mb4_unicode_ci
              AND BINARY g.numero_guia = BINARY i.numero_de_guia
          JOIN bylopez_lg_guia_estados_proveedor e ON e.guia_id = g.id AND e.proveedor = '99envios'
           SET i.cg_estado = 'omitida_antigua', i.cg_detalle = 'fecha_anterior_a_la_registrada',
               i.cg_mensaje = CONCAT('Ya está registrado «', e.estado_proveedor, '» del ', DATE_FORMAT(e.fecha_estado_proveedor, '%Y-%m-%d %H:%i:%s'),
                                     '; este reporte es anterior y no se aplica.'),
               i.cg_procesado_en = NOW()
         WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND i.fecha_actualizacion < e.fecha_estado_proveedor;

        -- R7 · mismo reporte ya registrado. Sin hito → sin_cambio (final). Con hito → PLANEADA 'ya_registrado' para la extensión, que solo
        --      completa lo que falte (p. ej. la validación) y no duplica nada.
        UPDATE bylopez_cgi_envios_completos i
          JOIN bylopez_lg_proveedor_transportadoras m
               ON m.proveedor = '99envios' AND m.activo = 1
              AND ((m.codigo_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.codigo_externo = BINARY i.transportadora)
                OR (m.nombre_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.nombre_externo = BINARY i.transportadora))
          JOIN bylopez_lg_guias g
               ON g.transportadora_codigo = m.transportadora_codigo
              AND g.numero_guia = i.numero_de_guia COLLATE utf8mb4_unicode_ci
              AND BINARY g.numero_guia = BINARY i.numero_de_guia
          JOIN bylopez_lg_guia_estados_proveedor e ON e.guia_id = g.id AND e.proveedor = '99envios'
           SET i.cg_estado = IF(BINARY i.estado_del_envio IN (BINARY 'Entregada', BINARY 'Devolución ratificada'), NULL, 'sin_cambio'), i.cg_detalle = 'ya_registrado',
               i.cg_procesado_en = IF(BINARY i.estado_del_envio IN (BINARY 'Entregada', BINARY 'Devolución ratificada'), NULL, NOW())
         WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND i.fecha_actualizacion = e.fecha_estado_proveedor;

        -- R8a · clasificación previa de lo que se aplica (cg_estado sigue NULL hasta R8d): detalle y conciliación.
        UPDATE bylopez_cgi_envios_completos i
          -- DISTINCT: la tabla derivada se materializa (MariaDB no la fusiona con la tabla que se actualiza).
          JOIN (SELECT DISTINCT cg_id, CASE WHEN aplica_contrapago IS NULL THEN NULL WHEN aplica_contrapago = 1 THEN valor_comercial ELSE 0 END AS reportado
                  FROM bylopez_cgi_envios_completos WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL) r ON r.cg_id = i.cg_id
          JOIN bylopez_lg_proveedor_transportadoras m
               ON m.proveedor = '99envios' AND m.activo = 1
              AND ((m.codigo_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.codigo_externo = BINARY i.transportadora)
                OR (m.nombre_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.nombre_externo = BINARY i.transportadora))
          JOIN bylopez_lg_guias g
               ON g.transportadora_codigo = m.transportadora_codigo
              AND g.numero_guia = i.numero_de_guia COLLATE utf8mb4_unicode_ci
              AND BINARY g.numero_guia = BINARY i.numero_de_guia
          LEFT JOIN bylopez_lg_guia_estados_proveedor e ON e.guia_id = g.id AND e.proveedor = '99envios'
           SET i.cg_detalle = IF(e.id IS NULL, 'insertada', 'actualizada'),
               i.cg_codigo = CASE WHEN r.reportado IS NULL THEN 'RECAUDO_SIN_DATO'
                                  WHEN r.reportado <> g.recaudo_esperado THEN 'DISCREPANCIA_RECAUDO' ELSE NULL END,
               i.cg_mensaje = CASE WHEN r.reportado IS NULL
                                       THEN 'No se pudo conciliar el recaudo: falta aplica_contrapago o valor_comercial. Se registró el estado.'
                                   WHEN r.reportado <> g.recaudo_esperado
                                       THEN CONCAT('Recaudo reportado ', r.reportado, ' ≠ recaudo esperado de la guía ', g.recaudo_esperado,
                                                   ' (diferencia ', r.reportado - g.recaudo_esperado, '). Se registró el estado; no se modificó la guía.')
                                   ELSE NULL END
         WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND NOT (i.cg_detalle <=> 'ya_registrado');

        -- R8b · historial (append-only), con el estado anterior.
        INSERT INTO bylopez_lg_guia_estado_proveedor_eventos
               (guia_id, proveedor, estado_proveedor, fecha_estado_proveedor, fecha_envio_proveedor, estado_anterior, fecha_estado_anterior,
                valor_comercial_reportado, valor_servicio_reportado, valor_seguro_reportado, seguro_reportado, aplica_contrapago_reportado,
                recaudo_reportado, recaudo_interno, recaudo_diferencia, conciliacion_recaudo, carga_id, fila_origen, created_at)
        SELECT g.id, '99envios', i.estado_del_envio, i.fecha_actualizacion, i.fecha_envio, e.estado_proveedor, e.fecha_estado_proveedor,
               i.valor_comercial, i.valor_servicio, i.valor_seguro_99, i.seguro, i.aplica_contrapago,
               r.reportado, g.recaudo_esperado, r.reportado - g.recaudo_esperado,
               CASE WHEN r.reportado IS NULL THEN 'sin_dato' WHEN r.reportado = g.recaudo_esperado THEN 'coincide' ELSE 'difiere' END,
               i.cg_carga_id, i.cg_fila_origen, NOW()
          FROM bylopez_cgi_envios_completos i
          JOIN (SELECT cg_id, CASE WHEN aplica_contrapago IS NULL THEN NULL WHEN aplica_contrapago = 1 THEN valor_comercial ELSE 0 END AS reportado
                  FROM bylopez_cgi_envios_completos WHERE cg_carga_id = p_carga_id) r ON r.cg_id = i.cg_id
          JOIN bylopez_lg_proveedor_transportadoras m
               ON m.proveedor = '99envios' AND m.activo = 1
              AND ((m.codigo_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.codigo_externo = BINARY i.transportadora)
                OR (m.nombre_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.nombre_externo = BINARY i.transportadora))
          JOIN bylopez_lg_guias g
               ON g.transportadora_codigo = m.transportadora_codigo
              AND g.numero_guia = i.numero_de_guia COLLATE utf8mb4_unicode_ci
              AND BINARY g.numero_guia = BINARY i.numero_de_guia
          LEFT JOIN bylopez_lg_guia_estados_proveedor e ON e.guia_id = g.id AND e.proveedor = '99envios'
         WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND i.cg_detalle IN ('insertada', 'actualizada');

        -- R8c · estado actual (uno por guía y proveedor).
        INSERT INTO bylopez_lg_guia_estados_proveedor
               (guia_id, proveedor, transportadora_codigo, numero_guia, estado_proveedor, fecha_estado_proveedor, fecha_envio_proveedor,
                valor_comercial_reportado, valor_servicio_reportado, valor_seguro_reportado, seguro_reportado, aplica_contrapago_reportado,
                recaudo_reportado, recaudo_interno, recaudo_diferencia, conciliacion_recaudo, carga_id, fila_origen, reportes, created_at, updated_at)
        SELECT g.id, '99envios', g.transportadora_codigo, g.numero_guia, i.estado_del_envio, i.fecha_actualizacion, i.fecha_envio,
               i.valor_comercial, i.valor_servicio, i.valor_seguro_99, i.seguro, i.aplica_contrapago,
               r.reportado, g.recaudo_esperado, r.reportado - g.recaudo_esperado,
               CASE WHEN r.reportado IS NULL THEN 'sin_dato' WHEN r.reportado = g.recaudo_esperado THEN 'coincide' ELSE 'difiere' END,
               i.cg_carga_id, i.cg_fila_origen, 1, NOW(), NOW()
          FROM bylopez_cgi_envios_completos i
          JOIN (SELECT cg_id, CASE WHEN aplica_contrapago IS NULL THEN NULL WHEN aplica_contrapago = 1 THEN valor_comercial ELSE 0 END AS reportado
                  FROM bylopez_cgi_envios_completos WHERE cg_carga_id = p_carga_id) r ON r.cg_id = i.cg_id
          JOIN bylopez_lg_proveedor_transportadoras m
               ON m.proveedor = '99envios' AND m.activo = 1
              AND ((m.codigo_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.codigo_externo = BINARY i.transportadora)
                OR (m.nombre_externo = i.transportadora COLLATE utf8mb4_unicode_ci AND BINARY m.nombre_externo = BINARY i.transportadora))
          JOIN bylopez_lg_guias g
               ON g.transportadora_codigo = m.transportadora_codigo
              AND g.numero_guia = i.numero_de_guia COLLATE utf8mb4_unicode_ci
              AND BINARY g.numero_guia = BINARY i.numero_de_guia
         WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND i.cg_detalle IN ('insertada', 'actualizada')
        ON DUPLICATE KEY UPDATE
               estado_proveedor = VALUES(estado_proveedor), fecha_estado_proveedor = VALUES(fecha_estado_proveedor),
               fecha_envio_proveedor = VALUES(fecha_envio_proveedor),
               valor_comercial_reportado = VALUES(valor_comercial_reportado), valor_servicio_reportado = VALUES(valor_servicio_reportado),
               valor_seguro_reportado = VALUES(valor_seguro_reportado), seguro_reportado = VALUES(seguro_reportado),
               aplica_contrapago_reportado = VALUES(aplica_contrapago_reportado),
               recaudo_reportado = VALUES(recaudo_reportado), recaudo_interno = VALUES(recaudo_interno),
               recaudo_diferencia = VALUES(recaudo_diferencia), conciliacion_recaudo = VALUES(conciliacion_recaudo),
               carga_id = VALUES(carga_id), fila_origen = VALUES(fila_origen), reportes = reportes + 1, updated_at = NOW();

        -- R8d · marca de la fila en la MISMA transacción que su efecto (estados SIN hito). Las de hito quedan planeadas para la extensión.
        UPDATE bylopez_cgi_envios_completos i
           SET i.cg_estado = 'aplicada', i.cg_procesado_en = NOW()
         WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND i.cg_detalle IN ('insertada', 'actualizada') AND NOT (BINARY i.estado_del_envio IN (BINARY 'Entregada', BINARY 'Devolución ratificada'));
        SET v_aplicadas = ROW_COUNT();

        SELECT COUNT(*) INTO v_planeadas FROM bylopez_cgi_envios_completos
         WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL AND cg_detalle IN ('insertada', 'actualizada', 'ya_registrado');

        SELECT COUNT(*) INTO v_rechazadas FROM bylopez_cgi_envios_completos
         WHERE cg_carga_id = p_carga_id AND cg_estado = 'rechazada' AND cg_procesado_en IS NOT NULL;
        IF v_rechazadas > 0 THEN SET p_resultado = 'PARCIAL'; END IF;
        SET p_mensaje = CONCAT(v_aplicadas, ' aplicada(s); ', v_planeadas, ' planeada(s) para hito logístico');
    END IF;
END$$
DELIMITER ;
