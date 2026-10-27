-- =====================================================================================================================
-- sp_cg_clientes_historicos_v1 — Tipo de cargue «Clientes históricos — 99 Envíos» (versión 1). Documentación: docs/14.
--
-- Contrato del motor (07 §8): firma fija, lee SOLO su carga (cg_carga_id = p_carga_id) de la intermedia
-- `bylopez_cgi_clientes_historicos`, modelo transaccional «motor», SIN COMMIT/ROLLBACK/DDL, SQL SECURITY INVOKER.
--
-- ESTE PROCEDIMIENTO SOLO CLASIFICA. No escribe clientes, teléfonos, direcciones ni antecedentes: escribe únicamente las marcas de
-- la intermedia. La escritura de negocio la hace la EXTENSIÓN del tipo (ClientesHistoricosExtension) por los servicios de Laravel
-- (normalizador de teléfonos, alta de cliente comercial, direcciones con DIVIPOLA, antecedentes), porque esas reglas ya existen
-- en PHP y no se duplican en SQL (frontera: docs/14 §5). Los valores DERIVADOS (teléfono E.164, nombre, cliente existente, municipio,
-- transportadora efectiva) los calcula esa extensión al preparar la carga; aquí se usan como hechos.
--
-- Reglas (sobre filas con cg_estado IS NULL), en este orden; la primera que aplica rechaza la fila:
--   R1 teléfono no normalizable ............................................... TELEFONO_NO_VALIDO
--   R2 nombre de una sola palabra (no se inventa apellido) ................... NOMBRE_INCOMPLETO
--   R3 transportadora informada sin equivalencia / ambigua ................... TRANSPORTADORA_SIN_EQUIVALENCIA / TRANSPORTADORA_AMBIGUA
--   R4 teléfono en varias fichas de cliente ................................... TELEFONO_EN_VARIAS_FICHAS
--   R5 cliente existente con ese teléfono y nombre no compatible .............. CLIENTE_NOMBRE_DISTINTO
--   R6 mismo teléfono con nombres distintos dentro del archivo (todas) ........ TELEFONO_COMPARTIDO_EN_ARCHIVO
--   -- solo EJECUTAR --
--   R7 el resto queda PLANEADO: cg_detalle = 'crear' (no existe el cliente) | 'completar' (existe y es compatible).
--      La extensión aplica cada fila y le pone la marca final (aplicada | sin_cambio | omitida_antigua | rechazada).
-- =====================================================================================================================
DROP PROCEDURE IF EXISTS sp_cg_clientes_historicos_v1;
DELIMITER $$
CREATE PROCEDURE sp_cg_clientes_historicos_v1(
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

    IF p_accion <> 'importar_historico' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Acción no soportada por sp_cg_clientes_historicos_v1';
    END IF;

    IF p_modo NOT IN ('VALIDAR', 'EJECUTAR') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Modo no soportado';
    END IF;

    -- R1 · teléfono
    UPDATE bylopez_cgi_clientes_historicos i
       SET i.cg_estado = 'rechazada', i.cg_codigo = 'TELEFONO_NO_VALIDO',
           i.cg_mensaje = CONCAT('El teléfono no se pudo normalizar (', IFNULL(i.telefono_estado, 'sin dato'), '). No se crea ni se identifica al cliente.'),
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND (i.telefono_e164 IS NULL OR i.telefono_estado IS NULL OR i.telefono_estado <> 'ok');

    -- R2 · nombre
    UPDATE bylopez_cgi_clientes_historicos i
       SET i.cg_estado = 'rechazada', i.cg_codigo = 'NOMBRE_INCOMPLETO',
           i.cg_mensaje = 'El destinatario trae una sola palabra: no se alcanza nombre y apellido y no se inventan.',
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND (i.nombre_pila IS NULL OR i.apellido IS NULL);

    -- R3 · transportadora informada (si no viene, el envío se registra con la transportadora sin determinar)
    UPDATE bylopez_cgi_clientes_historicos i
       SET i.cg_estado = 'rechazada',
           i.cg_codigo = IF(i.transportadora_estado = 'ambigua', 'TRANSPORTADORA_AMBIGUA', 'TRANSPORTADORA_SIN_EQUIVALENCIA'),
           i.cg_mensaje = CONCAT('La transportadora «', i.transportadora, '» de 99 Envíos ', IF(i.transportadora_estado = 'ambigua', 'coincide con más de una equivalencia activa.', 'no tiene equivalencia activa en Logística › proveedor_transportadoras.')),
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND i.transportadora_estado IN ('sin_equivalencia', 'ambigua');

    -- R4 · varias fichas
    UPDATE bylopez_cgi_clientes_historicos i
       SET i.cg_estado = 'rechazada', i.cg_codigo = 'TELEFONO_EN_VARIAS_FICHAS',
           i.cg_mensaje = 'Ese teléfono figura en varias fichas de cliente: identidad ambigua, revise manualmente.',
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND i.cliente_estado = 'varias_fichas';

    -- R5 · cliente existente con nombre no compatible
    UPDATE bylopez_cgi_clientes_historicos i
       SET i.cg_estado = 'rechazada', i.cg_codigo = 'CLIENTE_NOMBRE_DISTINTO',
           i.cg_mensaje = 'Ya existe un cliente con ese teléfono y su nombre no coincide con el del archivo: puede ser un teléfono compartido. No se unen personas por el teléfono.',
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL AND i.cliente_estado = 'nombre_distinto';

    -- R6 · mismo teléfono con nombres distintos dentro del archivo: identidad ambigua, se rechazan todas las filas de ese teléfono
    UPDATE bylopez_cgi_clientes_historicos i
      JOIN (SELECT telefono_e164 FROM bylopez_cgi_clientes_historicos
             WHERE cg_carga_id = p_carga_id AND cg_estado IS NULL AND telefono_e164 IS NOT NULL
             GROUP BY telefono_e164 HAVING COUNT(DISTINCT nombre_clave) > 1) x ON x.telefono_e164 = i.telefono_e164
       SET i.cg_estado = 'rechazada', i.cg_codigo = 'TELEFONO_COMPARTIDO_EN_ARCHIVO',
           i.cg_mensaje = 'El archivo trae el mismo teléfono con nombres distintos: identidad ambigua. No se crea ni se completa ningún cliente con él.',
           i.cg_procesado_en = IF(p_modo = 'EJECUTAR', NOW(), NULL)
     WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL;

    SELECT COUNT(*) INTO v_rechazadas FROM bylopez_cgi_clientes_historicos WHERE cg_carga_id = p_carga_id AND cg_estado = 'rechazada';

    IF p_modo = 'EJECUTAR' THEN
        -- R7 · plan para la extensión (sin marca final)
        UPDATE bylopez_cgi_clientes_historicos i
           SET i.cg_detalle = IF(i.cliente_id IS NULL, 'crear', 'completar')
         WHERE i.cg_carga_id = p_carga_id AND i.cg_estado IS NULL;
        SET v_planeadas = ROW_COUNT();
        SET p_mensaje = CONCAT(v_planeadas, ' fila(s) planeada(s) para aplicar');
    END IF;

    IF v_rechazadas > 0 THEN SET p_resultado = 'PARCIAL'; END IF;
END$$
DELIMITER ;
