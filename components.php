<?php

class file {
    public string $file_name;
}

class DB {
    /**
     * @var array $config
     */
    private array $config;
    private ?object $connection = null;

    public function __construct($config)
    {
        $this->config = array_slice($config, 0, 4);
    }


    public function getConnection() : ?PDO
    {
        if (is_null($this->connection)) {
            try {
                $this->connection = new PDO(
                    $this->prepareDsnString(),
                    $this->config['db_user'],
                    $this->config['db_pass']
                );
            } catch (Exception $e) {
                die('Error connect DB: '.$e->getMessage());
            }
        }

        return $this->connection;
    }

    private function prepareDsnString() : string
    {
        return sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8',
            $this->config['db_host'],
            $this->config['db_name']);
    }


    private function query(string $sql, array $params = []): ?object
    {
        $pdoStatement = $this->getConnection()->prepare($sql);
        $pdoStatement->execute($params);
        return $pdoStatement;
    }


    public function execute(string $sql, array $params = []): bool
    {
        $this->query($sql, $params);
        return true;
    }

    public function queryAll(string $sql, array $params = []) : ?array
    {
        $pdoStatement = $this->query($sql, $params);
        return $pdoStatement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function queryOne(string $sql, array $params = []) : ?string
    {
        return $this->queryAll($sql, $params)[0];
    }

}

class CheckAndUpload {
    /**
     * @var array
     */
    private array $config;
    private ?object $db;

    public function __construct($config)
    {
        $this->config = $config;
        $this->db = new DB($config);
    }

    public function init()
    {
        /** Logging init connection */
        $params = ['DB' ,'Соединение установлено', 'cron'];
        $this->createLogsRow($params);

        /**
         * Get file in directory
         * Get file from DB
         * Check new and delete file and logging
         */
        $getFilesRemoteDir = $this->checkingDir();
        $getFileFromDb = $this->getFilesFromDB();
        $this->analyzingFiles($getFilesRemoteDir, $getFileFromDb);

        /**
         * Get next not upload file from DB
         * Get diff next upload and last upload files
         * Run upload
         */
        $forUploadData = $this->getNotUploadFile();
        $getUploadData = $this->checkDiffLastFile($forUploadData);
        $this->run($getUploadData);

        /** Logging close connection */
        $params = ['DB' ,'Соединение закрыто', 'cron'];
        $this->createLogsRow($params);
    }

    /** Log function */
    private function createLogsRow(array $params) : void
    {
        $sql = 'INSERT INTO logs (type, message, target) VALUES (?, ?, ?)';
        $this->db->execute($sql, $params);
    }

    /** Check directory */
    private function checkingDir()
    {
        $_result = scandir($this->config['dir']);
        unset($_result[array_search('.', $_result, true)]);
        unset($_result[array_search('..', $_result, true)]);
        foreach ($_result as $item) {
            if (!preg_match('/.json$/m', $item)) {
                $params = ['FS' ,'Не подходящий формат файла', $item];
                $this->createLogsRow($params);
                unset($_result[array_search($item, $_result, true)]);
            }
        }
        return $_result;
    }

    /** Get file from DB */
    private function getFilesFromDB(): ?array
    {
        $sql = 'SELECT file_name FROM uploader_data WHERE deleted_at IS NULL';
        return $this->db->queryAll($sql);
    }

    /** Check new and delete file and logging */
    private function analyzingFiles ($getFilesRemoteDir, $getFileFromDb)
    {
        // Check filename from db with file from dir, if db > dir - mark delete
        if (count($getFileFromDb) > 0) {
            foreach ($getFileFromDb as $file) {
                if (!in_array($file, $getFilesRemoteDir)) {
                    // check deleted mark in db
                    $sql = 'SELECT deleted_at FROM uploader_data WHERE file_name=? AND deleted_at is NULL';
                    $isDelete = $this->db->queryOne($sql, [$file]);
                    if (is_null($isDelete)) {
                        // mark deleted
                        $sql = 'UPDATE uploader_data SET deleted_at=CURRENT_TIMESTAMP() WHERE file_name=?';
                        $this->db->execute($sql, [$file]);
                        // add logs
                        $params = ['FS' ,'Удален файл', $file];
                        $this->createLogsRow($params);
                    }
                }
            }
        }

        // Check new file and add in db and logging
        if (count($getFilesRemoteDir) > 0) {
            foreach ($getFilesRemoteDir as $file) {
                if (!in_array($file, $getFileFromDb)) {
                    // add file
                    $sql = 'INSERT INTO uploader_data (file_name) VALUE (?)';
                    $this->db->execute($sql, [$file]);
                    // add logs
                    $params = ['FS' ,'Загружен файл', $file];
                    $this->createLogsRow($params);
                }
            }
        }
    }

    /** Get next not upload file from DB  */
    private function getNotUploadFile() : array
    {
        $sql =
            'SELECT file_name FROM uploader_data WHERE upload IS NULL AND deleted_at IS NULL ORDER BY created_at LIMIT 1';
        $_result = $this->db->queryOne($sql);
        $sql = 'SELECT file_name FROM uploader_data WHERE upload IS NOT NULL AND deleted_at IS NULL ORDER BY upload DESC LIMIT 1';
        $_lastUploadFile = $this->db->queryOne($sql);

        return ['target' => $_result, 'last_file' => $_lastUploadFile];
    }

    /** Check diff next upload and last upload files */
    private function checkDiffLastFile($forUploadData) : array
    {
        if (is_null($forUploadData['target'])) {
            return ['data' => NULL, 'target_file' => NULL];
        }
        $_content = file_get_contents($this->config['dir'].$forUploadData['target']);
        $_data = json_decode($_content, true);

        /** run FULL upload */
        if (!$forUploadData['last_file']) {
            return ['data' => $_data, 'target_file' => $forUploadData['target']];
        } else {
            /** get diff data and run update */
            $_old_content = file_get_contents($this->config['dir'].$forUploadData['last_file']);
            $_old_data = json_decode($_old_content, true);

            /** prepare data */
            $diff_array = [];
            $_index = 0;

            /** Обойдем массив новых данных */
            foreach ($_data as $item_actual) {

                /** Обойдем массив новых данных */
                foreach ($_old_data as $item_old) {

                    /** Сравним текущий элемент с элементами старого массива по полю _1c_product_id; */
                    if ($item_actual['_1c_product_id'] === $item_old['_1c_product_id']) {
                        $isDiff = json_encode($item_actual) === json_encode($item_old);
                        if (!$isDiff) {
                            $diff_array[] = $item_actual;
                        }
                        $_index++;
                        continue 2;
                    }

                    $_index++;
                }

                /** Если индекс текущего элемента больше длины старого массива,
                 * это новый элемент, добавляем его в массив изменений;
                 */
                if ($_index >= count($_old_data)) {
                    $diff_array[] = $item_actual;
                }
            }


            return ['data' => $diff_array, 'target_file' => $forUploadData['target']];
        }
    }

    /** Upload data to remote host */
    private function run($data) : void
    {

        if (is_null($data['target_file'])) {
            $params = ['FS', 'Нет новых файлов', 'cron'];
        } else if (count($data['data']) === 0) {
            $params = ['FS', 'Нет новых данных', $data['target_file']];

            $sql = 'UPDATE uploader_data SET upload=CURRENT_TIMESTAMP(), diff_length=? WHERE file_name=? AND upload IS NULL';
            $_params = [count($data['data']), $data['target_file']];
            $this->db->execute($sql, $_params);
        } else {
            $opts = array('http' =>
                array(
                    'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method'  => 'POST',
                    'content' => http_build_query(array('upload_data' => json_encode($data['data']))),
                )
            );

            $context  = stream_context_create($opts);
            $url = $this->config['url'].'&token='.$this->config['url_token'];
            $result = file_get_contents($url, false, $context);

            if ($result === false) {
                $params = ['REQUEST', 'Произошла критическая ошибка отправки данных', 'cron'];
                $this->createLogsRow($params);

                die('Произошла критическая ошибка отправки данных');
            }

            /** SUCCESS */
            $sql = 'UPDATE uploader_data SET upload=CURRENT_TIMESTAMP(), diff_length=? WHERE file_name=? AND upload IS NULL AND deleted_at IS NULL';
            $params = [count($data['data']), $data['target_file']];
            $this->db->execute($sql, $params);

            $params = ['DB', 'Отправлены новые данные', 'cron'];
        }

        $this->createLogsRow($params);
    }
}
