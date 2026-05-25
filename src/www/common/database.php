<?php

function unixToSQLdatetime($unixTimestamp) {
    return date("Y-m-d H:i:s", $unixTimestamp);
}

function addtolog($param1, $param2) {
    $GLOBALS['DB']->query('INSERT INTO applog ( param1, param2 ) VALUES ( ?, ? )',  [$param1, $param2]);
}

function inc_trn_idx($userIDX) {
    $GLOBALS["DB"]->query("UPDATE users SET last_trn_idx = LAST_INSERT_ID(last_trn_idx + 1) WHERE idx = ?;"
            //" SELECT LAST_INSERT_ID();"
            , [$userIDX]);
    return $GLOBALS["DB"]->getPDO()->query("SELECT LAST_INSERT_ID();")->fetchColumn();
}

function inc_settle_idx($userIDX) {
    $GLOBALS["DB"]->query("UPDATE users SET last_settle_idx = LAST_INSERT_ID(last_settle_idx + 1) WHERE idx = ?;"
            //" SELECT LAST_INSERT_ID();"
            , [$userIDX]);
    return $GLOBALS["DB"]->getPDO()->query("SELECT LAST_INSERT_ID();")->fetchColumn();
}

function loadconfig() {
    $table = $GLOBALS['DB']->query("select id, value from config ;",[]);
    return array_column($table, "value", "id");
    /*$res = array();
    for ($i = 0; $i < count($table); $i++) {
        $res[trim($table[$i]['id'])] = $table[$i]['value'];
    }

    return $res;*/
}

function writeconfig($id, $value) {
    $GLOBALS['DB']->query(
        "INSERT INTO config (id, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)",
        [$id, $value]
    );

/*    $GLOBALS['DB']->query("UPDATE config set value = ? WHERE id = ? ", [$value, $id]);
    if ($GLOBALS['DB']->affectedRows() < 1) {
        $GLOBALS['DB']->query("INSERT INTO config (id, value) VALUES (?,?) ", [$id, $value]);
    }*/
}


class Database {

    private PDO $pdo;
    private ?PDOStatement $lastStmt = null;

    public function __construct(
            string $host = NULL,
            string $dbname = NULL,
            string $user = NULL,
            string $password = NULL,
            int $port = 3306
    ) {

        $cfg = require __DIR__ . '/config.php';
        if ($host == NULL) {
            $host = $cfg["MYSQL_HOST"];
        }
        if ($dbname == NULL) {
            $dbname = $cfg["MYSQL_DBNAME"];
        }
        if ($user == NULL) {
            $user = $cfg["MYSQL_USER"];
        }
        if ($password == NULL) {
            $password = $cfg["MYSQL_PASSWORD"];
        }
        
        if (($host ?? "") == "") {
          $dsn = "mysql:host=db;dbname={$dbname};charset=utf8mb4";
        } else {
          $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";    
        }
        try {
            $this->pdo = new PDO(
                    $dsn,
                    $user,
                    $password,
                    [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
                    ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    // Jednoduch� SELECT / INSERT / UPDATE / DELETE s prepared statement
    public function query(string $sql, array $params = []): array|bool {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $this->lastStmt = $stmt;
            // Vr�t� data, pokud SELECT, jinak true/false
            if (stripos(trim($sql), 'SELECT') === 0) {
                return $stmt->fetchAll();
            }
            return true;
        } catch (PDOException $e) {
            throw new RuntimeException('Query failed: ' . $e->getMessage());
        }
    }

    // Vr�t� posledn� vlo�en� ID
    public function lastInsertId(): string {
        return $this->pdo->lastInsertId();
    }

    // Pro transakce
    public function beginTransaction(): bool {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool {
        return $this->pdo->commit();
    }

    public function rollBack(): bool {
        return $this->pdo->rollBack();
    }

    public function getPDO(): PDO {
        return $this->pdo;
    }

    public function affectedRows(): int {
        return $this->lastStmt ? $this->lastStmt->rowCount() : 0;
    }
    
}
