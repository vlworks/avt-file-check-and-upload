<?php

namespace CheckFilesAndUploadData;

class GetData
{
    private function getContent($filePath) {
        if (file_exists($filePath))
            return json_decode(file_get_contents($filePath), false);
    }

    function getData(array $files) {
        if (count($files) === 2) {
            $newData = $this->getContent($files[0]);
            $lastData = $this->getContent($files[1]);


        }

    }
}
