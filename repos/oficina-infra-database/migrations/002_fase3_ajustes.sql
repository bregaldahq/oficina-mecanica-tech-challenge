-- ============================================================
-- 002_fase3_ajustes.sql
-- Os 10 ajustes de schema da Fase 3 (seção 6 dos Contratos).
--
-- Idempotência: o MySQL 8.0 não aceita `IF NOT EXISTS` em ADD COLUMN,
-- ADD INDEX nem ADD CONSTRAINT. Onde isso ocorre, o arquivo consulta o
-- information_schema e monta o ALTER dinamicamente com PREPARE/EXECUTE,
-- caindo para `SELECT 1` quando o objeto já existe. Onde o MySQL é
-- naturalmente idempotente (MODIFY COLUMN, CREATE TABLE IF NOT EXISTS),
-- o statement é direto.
--
-- Ainda assim, o arquivo é aplicado UMA ÚNICA VEZ por ambiente: o runner
-- `bin/migrate.php` registra a versão na tabela `schema_migrations` e nunca
-- reexecuta um arquivo já registrado. A idempotência aqui é uma rede de
-- segurança para reaplicação manual, não o mecanismo de controle.
--
-- Sem DELIMITER. Um statement por `;` no fim da linha.
-- ============================================================

-- ------------------------------------------------------------
-- 1. customers.status
-- ------------------------------------------------------------
SET @stmt = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE customers ADD COLUMN status ENUM(''ACTIVE'',''INACTIVE'',''BLOCKED'') NOT NULL DEFAULT ''ACTIVE'' AFTER document',
    'SELECT 1')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'status');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 2. customers.email e customers.phone
-- ------------------------------------------------------------
SET @stmt = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE customers ADD COLUMN email VARCHAR(255) NULL AFTER name',
    'SELECT 1')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'email');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @stmt = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE customers ADD COLUMN phone VARCHAR(20) NULL AFTER email',
    'SELECT 1')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'phone');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 3. Histórico de transições de status
--    Alimenta o `durationSeconds` do evento ServiceOrderStatusChanged
--    e o dashboard de tempo médio por etapa.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_order_status_history (
    id               CHAR(36)     NOT NULL,
    service_order_id CHAR(36)     NOT NULL,
    from_status      VARCHAR(30)  NULL,
    to_status        VARCHAR(30)  NOT NULL,
    changed_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    changed_by       VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_sosh_order_changed (service_order_id, changed_at),
    KEY idx_sosh_status_changed (to_status, changed_at),
    CONSTRAINT fk_sosh_order FOREIGN KEY (service_order_id)
        REFERENCES service_orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Índice de listagem por status + data (tela e dashboard)
-- ------------------------------------------------------------
SET @stmt = (SELECT IF(COUNT(*) = 0,
    'CREATE INDEX idx_orders_status_created ON service_orders (status, created_at)',
    'SELECT 1')
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'service_orders' AND index_name = 'idx_orders_status_created');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 5. Índices das chaves estrangeiras mais consultadas
-- ------------------------------------------------------------
SET @stmt = (SELECT IF(COUNT(*) = 0,
    'CREATE INDEX idx_orders_customer ON service_orders (customer_id)',
    'SELECT 1')
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'service_orders' AND index_name = 'idx_orders_customer');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @stmt = (SELECT IF(COUNT(*) = 0,
    'CREATE INDEX idx_orders_vehicle ON service_orders (vehicle_id)',
    'SELECT 1')
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'service_orders' AND index_name = 'idx_orders_vehicle');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @stmt = (SELECT IF(COUNT(*) = 0,
    'CREATE INDEX idx_vehicles_customer ON vehicles (customer_id)',
    'SELECT 1')
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'vehicles' AND index_name = 'idx_vehicles_customer');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 6. service_orders.status vira ENUM com os 7 estados da máquina
--    de estados de ServiceOrder. MODIFY é idempotente.
-- ------------------------------------------------------------
ALTER TABLE service_orders
    MODIFY COLUMN status ENUM('RECEIVED','DIAGNOSIS','AWAITING_APPROVAL','EXECUTING','REJECTED','FINISHED','DELIVERED')
    NOT NULL DEFAULT 'RECEIVED';

-- ------------------------------------------------------------
-- 7. Controle otimista e piso de estoque em parts_inventory
-- ------------------------------------------------------------
SET @stmt = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE parts_inventory ADD COLUMN version INT NOT NULL DEFAULT 0',
    'SELECT 1')
  FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'parts_inventory' AND column_name = 'version');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @stmt = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE parts_inventory ADD CONSTRAINT chk_parts_stock_non_negative CHECK (stock_quantity >= 0)',
    'SELECT 1')
  FROM information_schema.table_constraints
  WHERE table_schema = DATABASE() AND table_name = 'parts_inventory' AND constraint_name = 'chk_parts_stock_non_negative');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 8. Unicidade das tabelas de junção: a mesma peça/serviço não pode
--    aparecer duas vezes na mesma OS (a quantidade é uma coluna).
-- ------------------------------------------------------------
SET @stmt = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE service_order_parts ADD CONSTRAINT uk_sop_order_part UNIQUE (service_order_id, parts_inventory_id)',
    'SELECT 1')
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'service_order_parts' AND index_name = 'uk_sop_order_part');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @stmt = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE service_order_services ADD CONSTRAINT uk_sos_order_service UNIQUE (service_order_id, service_catalog_id)',
    'SELECT 1')
  FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'service_order_services' AND index_name = 'uk_sos_order_service');
PREPARE s FROM @stmt;
EXECUTE s;
DEALLOCATE PREPARE s;

-- ------------------------------------------------------------
-- 9. vehicles.year: INT (4 bytes) vira SMALLINT UNSIGNED (2 bytes).
--    Faixa 0..65535 cobre qualquer ano de fabricação plausível.
-- ------------------------------------------------------------
ALTER TABLE vehicles MODIFY COLUMN year SMALLINT UNSIGNED NOT NULL;

-- ------------------------------------------------------------
-- 10. TIMESTAMP -> DATETIME(3).
--     TIMESTAMP tem o limite de 2038 e converte para o fuso da sessão;
--     DATETIME(3) guarda o instante literal em UTC com milissegundos,
--     que é a precisão de que os eventos de New Relic precisam.
-- ------------------------------------------------------------
ALTER TABLE customers
    MODIFY COLUMN created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3);

ALTER TABLE service_orders
    MODIFY COLUMN created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3);

ALTER TABLE service_orders
    MODIFY COLUMN updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3);
