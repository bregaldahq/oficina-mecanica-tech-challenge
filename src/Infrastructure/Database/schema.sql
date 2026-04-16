-- ============================================================
-- Schema da Oficina Mecânica (conforme especificação)
-- ============================================================

CREATE DATABASE IF NOT EXISTS oficina_mecanica CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE oficina_mecanica;

-- Clientes
CREATE TABLE IF NOT EXISTS customers (
    id         VARCHAR(36)  NOT NULL,
    name       VARCHAR(255) NOT NULL,
    document   VARCHAR(14)  NOT NULL COMMENT 'CPF ou CNPJ apenas com números',
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_customers_document (document)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Veículos
CREATE TABLE IF NOT EXISTS vehicles (
    id            VARCHAR(36)  NOT NULL,
    customer_id   VARCHAR(36)  NOT NULL,
    license_plate VARCHAR(7)   NOT NULL,
    brand         VARCHAR(100) NOT NULL,
    model         VARCHAR(100) NOT NULL,
    year          INT          NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_vehicles_license_plate (license_plate),
    CONSTRAINT fk_vehicles_customer FOREIGN KEY (customer_id)
        REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de Serviços
CREATE TABLE IF NOT EXISTS service_catalog (
    id                     VARCHAR(36)    NOT NULL,
    description            VARCHAR(255)   NOT NULL,
    base_price             DECIMAL(10, 2) NOT NULL,
    estimated_time_minutes INT            NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estoque de Peças
CREATE TABLE IF NOT EXISTS parts_inventory (
    id             VARCHAR(36)    NOT NULL,
    description    VARCHAR(255)   NOT NULL,
    price          DECIMAL(10, 2) NOT NULL,
    stock_quantity INT            NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ordens de Serviço
CREATE TABLE IF NOT EXISTS service_orders (
    id           VARCHAR(36)    NOT NULL,
    customer_id  VARCHAR(36)    NOT NULL,
    vehicle_id   VARCHAR(36)    NOT NULL,
    status       VARCHAR(50)    NOT NULL DEFAULT 'RECEIVED',
    total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    created_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_orders_vehicle  FOREIGN KEY (vehicle_id)  REFERENCES vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Serviços da Ordem de Serviço
CREATE TABLE IF NOT EXISTS service_order_services (
    id               VARCHAR(36)    NOT NULL,
    service_order_id VARCHAR(36)    NOT NULL,
    service_catalog_id VARCHAR(36)  NOT NULL,
    price_charged    DECIMAL(10, 2) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_sos_order   FOREIGN KEY (service_order_id)  REFERENCES service_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_sos_catalog FOREIGN KEY (service_catalog_id) REFERENCES service_catalog (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Peças da Ordem de Serviço
CREATE TABLE IF NOT EXISTS service_order_parts (
    id                 VARCHAR(36)    NOT NULL,
    service_order_id   VARCHAR(36)    NOT NULL,
    parts_inventory_id VARCHAR(36)    NOT NULL,
    quantity_used      INT            NOT NULL,
    unit_price_charged DECIMAL(10, 2) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_sop_order   FOREIGN KEY (service_order_id)   REFERENCES service_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_sop_part    FOREIGN KEY (parts_inventory_id)  REFERENCES parts_inventory (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
