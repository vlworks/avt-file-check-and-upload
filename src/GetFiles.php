<?php

namespace CheckFilesAndUploadData;

class GetFiles
{
    /**
     * @var string path local folder
     */
    private string $path;

    /**
     * @var string[] exclude files !NOT types
     */
    private array $exclude;

    public function __construct($path, $exclude = [])
    {
        $this->path = $path;

        /**
         * Merge user exclude and . ..
         */
        $this->exclude = array_merge($exclude, ['.', '..']);
    }

    function getFiles($sort = 'DESC', $count = 2): array {
        $files = [];

        foreach (array_diff(scandir($this->path), $this->exclude) as $file) {

            /**
             * Only *.json files
             */
            if (!preg_match('/.json$/m', $file))
                continue;

            /**
             * Get timestamp create/change files date (use create)
             */
            $files[$this->path.$file] = filemtime($this->path . $file);
        }

        /**
         * Use Sort, DESC default
         */
        if ($sort === 'DESC')
            arsort($files);
        else
            asort($files);

        /**
         * Return only $count files
         */
        return $files = array_keys(array_slice($files, 0, $count));
    }

}
