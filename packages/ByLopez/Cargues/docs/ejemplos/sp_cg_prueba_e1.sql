-- Procedimiento SINTÉTICO para probar E1 (solo modo VALIDAR). SQL SECURITY INVOKER es OBLIGATORIO: con DEFINER (el valor por
-- defecto) correría con los privilegios de su definidor y la cuenta restringida de VALIDAR no limitaría nada (docs/12). No toca tablas de negocio: solo marca la intermedia.
-- Rechaza las filas sin teléfono. El modo EJECUTAR no existe todavía (E2): responde con un error explícito.
DROP PROCEDURE IF EXISTS sp_cg_prueba_e1;
DELIMITER $$
CREATE PROCEDURE sp_cg_prueba_e1(
    IN  p_carga_id  BIGINT,
    IN  p_accion    VARCHAR(40),
    IN  p_modo      VARCHAR(10),
    OUT p_resultado VARCHAR(10),
    OUT p_mensaje   VARCHAR(500)
)
SQL SECURITY INVOKER
BEGIN
    SET p_resultado = 'OK';
    SET p_mensaje = NULL;
    IF p_modo = 'VALIDAR' THEN
        UPDATE bylopez_cgi_prueba_e1
           SET cg_estado = 'rechazada', cg_codigo = 'SIN_TELEFONO', cg_mensaje = 'La fila no trae teléfono'
         WHERE cg_carga_id = p_carga_id AND telefono IS NULL;
        IF ROW_COUNT() > 0 THEN SET p_resultado = 'PARCIAL'; END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'EJECUTAR se habilita en la entrega E2';
    END IF;
END$$
DELIMITER ;
