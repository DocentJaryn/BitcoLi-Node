SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS bitcoliv2
CHARACTER SET utf8mb4
COLLATE utf8mb4_general_ci;

USE bitcoliv2;

DELIMITER //

CREATE FUNCTION random_string(len INT)
RETURNS VARCHAR(255)
DETERMINISTIC
BEGIN
  DECLARE chars VARCHAR(62) DEFAULT 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  DECLARE result VARCHAR(255) DEFAULT '';
  DECLARE i INT DEFAULT 0;

  WHILE i < len DO
    SET result = CONCAT(result, SUBSTRING(chars, FLOOR(RAND()*62)+1, 1));
    SET i = i + 1;
  END WHILE;

  RETURN result;
END //

DELIMITER ;


CREATE TABLE IF NOT EXISTS applog (
  dt datetime DEFAULT current_timestamp(),
  param1 text DEFAULT NULL,
  param2 text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE IF NOT EXISTS config (
  id varchar(45) NOT NULL,
  `value` text DEFAULT NULL,
  description text DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS sessions (
  sid char(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  pub_key varchar(45) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  expiration datetime DEFAULT NULL,
  encrypt_key binary(32) DEFAULT NULL,
  decrypt_key binary(32) DEFAULT NULL,
  PRIMARY KEY (sid),
  UNIQUE KEY sid_UNIQUE (sid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `transactions` (
  `idx` int(11) NOT NULL AUTO_INCREMENT,
  `created` datetime DEFAULT NULL,
  `settled` datetime DEFAULT NULL,
  `settled_lnd` datetime DEFAULT NULL,
  `expiry_sec` int(11) NOT NULL DEFAULT 0,
  `payment_hash` varchar(65) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `payment_status` tinyint(4) DEFAULT 0 COMMENT '0=faktura vystavena, neovlivňuje to výsledek  1=probíhá platba (částka je blokovaná, zůstatek snížen)  \\n2=vše OK   null=platba selhala   ',
  `user_idx` int(11) NOT NULL,
  `invoice` text DEFAULT NULL,
  `requested_msat` bigint(20) NOT NULL DEFAULT 0,
  `paid_msat` bigint(20) NOT NULL DEFAULT 0,
  `fee_msat` int(11) NOT NULL DEFAULT 0,
  `max_fee_msat` int(11) NOT NULL DEFAULT 0,
  `payment_preimage` varchar(64) DEFAULT NULL,
  `sign_client` text DEFAULT NULL COMMENT 'sign_client=sign(node_pubkey+"&"+invoice+"&"+amount_paid_msat)        podpis je jen u plateb, ne u vystavených faktur',
  `sign_node` text DEFAULT NULL COMMENT 'sign_node=sign(client_pubkey+"&"+invoice+"&"+amount_paid_msat+"&"+fee_msat)      po zaplacení faktury se podpis aktualizuje',
  `settle_idx` int(11) DEFAULT NULL,
  `trn_idx` int(11) DEFAULT NULL,
  `memo` text DEFAULT NULL,
  `ext_info` text DEFAULT NULL,
  PRIMARY KEY (`idx`),
  UNIQUE KEY `IDX_UNIQUE` (`idx`),
  UNIQUE KEY `IDX_payment_hash` (`payment_hash`,`payment_status`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `used_nonces` (
  `sid` varchar(10) NOT NULL,
  `nonce` varbinary(12) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`nonce`,`sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `idx` int(11) NOT NULL AUTO_INCREMENT,
  `pub_key` varchar(45) NOT NULL,
  `created` datetime DEFAULT NULL,
  `lastLogin` datetime DEFAULT NULL,
  `last_settle_idx` int(11) NOT NULL DEFAULT 0,
  `last_trn_idx` int(11) NOT NULL DEFAULT 0,
  `level` int(10) unsigned NOT NULL DEFAULT 1,
  `terms_hash` varchar(44) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `terms_time` datetime DEFAULT NULL,
  `terms_sign` varchar(88) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `terms_valid_until` datetime DEFAULT NULL,
  PRIMARY KEY (`idx`),
  UNIQUE KEY `pubKey_UNIQUE` (`pub_key`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `user_group` (
  `level` int(10) unsigned NOT NULL,
  `max_balance_sat` int(10) unsigned NOT NULL,
  `fee_ppm` int(10) unsigned NOT NULL,
  `name` varchar(30) DEFAULT NULL,
  `terms_hash` varchar(45) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  PRIMARY KEY (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `invitations` (
  `idx` int(11) NOT NULL AUTO_INCREMENT,
  `level` int(11) unsigned NOT NULL,
  `id` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `description` varchar(255) NOT NULL,
  `cnt` int(11) NOT NULL,
  `used` int(11) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`idx`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `terms_history` (
  `hash` varchar(44) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `terms` text DEFAULT NULL,
  `terms_time` datetime DEFAULT NULL,
  `max_balance_sat` int(10) unsigned NOT NULL,
  `fee_ppm` int(10) unsigned NOT NULL,
  PRIMARY KEY (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `backup_nodes` (
  `idx` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(255) NOT NULL COMMENT 'URL backup nodu, např. http://xyz.onion',
  `use_tor` tinyint(4) NOT NULL DEFAULT 1,
  `ed_pub` varchar(64) NOT NULL COMMENT 'Ed25519 veřejný klíč v base64url (43 znaků)',
  `pswd` varchar(45) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL COMMENT 'Volitelný popis, např. "Honzův node"',
  `last_backup` datetime DEFAULT NULL COMMENT 'Datum a čas poslední zálohy (UTC)',
  `last_result` varchar(500) DEFAULT NULL COMMENT 'Prázdný řetězec = úspěch, jinak popis chyby',
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`idx`),
  UNIQUE KEY `uq_url` (`url`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;




INSERT INTO `config` (`id`, `value`, `description`) VALUES ('settle_index', '1', 'Index poslední zpracované LND transakce');
INSERT INTO `config` (`id`, `value`, `description`) VALUES ('node_name', 'default name', 'Název serveru');
INSERT INTO `config` (`id`, `value`, `description`) VALUES ('terms', '{\n    \"lng\": {\n        \"en\": \"This wallet is operated for a small circle of acquaintances and is intended primarily for everyday Lightning payments.\\r\\nI recommend storing only small amounts in it.\\r\\nIn the event of service termination, notice will be provided at least 90 days in advance.\",\n        \"cs\": \"Tato peněženka je provozována pro úzký okruh známých a slouží primárně pro běžné Lightning platby.\\r\\nDoporučuji v ní uchovávat pouze menší částky.\\r\\nV případě ukončení provozu bude upozornění zveřejněno minimálně 90 dní předem.\"\n    },\n    \"node_pub_key\": \"\",\n    \"max_balance_sat\": 0,\n    \"fee_ppm\": 0\n}', 'podmínky používání');
INSERT INTO `config` (`id`, `value`, `description`) VALUES ('backup_pswd', random_string(15), 'Heslo pro zálohování na mém serveru');

INSERT INTO `user_group` (`level`, `max_balance_sat`, `fee_ppm`, `name`) VALUES ('0', '0', '0', 'Low trust');
INSERT INTO `user_group` (`level`, `max_balance_sat`, `fee_ppm`, `name`) VALUES ('1', '10000', '0', 'New');
INSERT INTO `user_group` (`level`, `max_balance_sat`, `fee_ppm`, `name`) VALUES ('2', '100000', '0', 'Limited');
INSERT INTO `user_group` (`level`, `max_balance_sat`, `fee_ppm`, `name`) VALUES ('3', '1000000', '0', 'Trusted');
INSERT INTO `user_group` (`level`, `max_balance_sat`, `fee_ppm`, `name`) VALUES ('4', '1000000', '0', 'Full');

INSERT INTO `invitations` (`level`, `id`, `description`, `cnt`, `used`, `created`) VALUES ('1', random_string(15), 'Default invitation', '100', '0', now());

INSERT INTO `backup_nodes` (`url`, `ed_pub`, `pswd`, `description`) VALUES ('#defaultbackup#', '', '', 'Default backup server (no guarantee)');

INSERT INTO `config` (`id`, `value`, `description`) VALUES ('db_version',   '1', 'DB schema version; compared with DB_VERSION constant in constants.php');
INSERT INTO `config` (`id`, `value`, `description`) VALUES ('db_upgrading', '0', '1 = DB schema migration in progress, app shows upgrading.php');
/* 
!!! Pouze pro usnadnění vývoje, v produkci se musí smazat !!!
*/

CREATE USER 'remoteuser'@'%' IDENTIFIED BY 'tajneheslo';
GRANT ALL PRIVILEGES ON bitcoliv2.* TO 'remoteuser'@'%';
FLUSH PRIVILEGES;