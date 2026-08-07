<?php

namespace NeoFramework\Core;

use MatthiasMullie\Minify\CSS;
use MatthiasMullie\Minify\JS;

class Bundler
{
    public static function build(array $config){

        // 755 em decimal vira 0o1363 (com sticky bit); o modo precisa ser octal.
        // E sem recursive a criação falha quando public/assets ainda não existe.
        if(!is_dir(Functions::getRoot()."public/assets/css")){
            mkdir(Functions::getRoot()."public/assets/css",0755,true);
        }

        if(!is_dir(Functions::getRoot()."public/assets/js")){
            mkdir(Functions::getRoot()."public/assets/js",0755,true);
        }

        self::deleteFiles(Functions::getRoot()."public/assets/css");
        self::deleteFiles(Functions::getRoot()."public/assets/js");

        $configCss = isset($config["css"])?$config["css"]:[];
       
        foreach ($configCss as $key => $files){
            $minifier = new CSS();

            self::getFiles($minifier,Functions::getRoot()."Resources/Css",$files);

            $minifier->minify(Functions::getRoot()."public/assets/css/".$key."_".Functions::generateId().".css");
        }
        
        $minifier = new CSS();

        self::getFiles($minifier,Functions::getRoot()."Resources/Css");

        $minifier->minify(Functions::getRoot()."public/assets/css/"."ALL_".Functions::generateId().".css");

        print_r(scandir(Functions::getRoot()."public/assets/css"));
        echo PHP_EOL;

        $configJs = isset($config["js"])?$config["js"]:[];
        
        foreach ($configJs as $key => $files){
            $minifier = new JS();

            self::getFiles($minifier,Functions::getRoot()."Resources/Js",$files);

            $minifier->minify(Functions::getRoot()."public/assets/js/".$key."_".Functions::generateId().".js");
        }
        
        $minifier = new JS();

        self::getFiles($minifier,Functions::getRoot()."Resources/Js");

        $minifier->minify(Functions::getRoot()."public/assets/js/"."ALL_".Functions::generateId().".js");
        
        print_r(scandir(Functions::getRoot()."public/assets/js"));
        echo PHP_EOL;
    }

    public static function getCssFile($key = "ALL"):string
    {
        return self::findBundle("public/assets/css", $key, "css");
    }

    public static function getJsFile($key = "ALL"):string
    {
        return self::findBundle("public/assets/js", $key, "js");
    }

    /**
     * Localiza o bundle gerado para uma chave, ignorando "." e ".." — que a
     * varredura anterior tratava como candidatos válidos.
     */
    private static function findBundle(string $relativePath, string $key, string $extension): string
    {
        $dir = Functions::getRoot().$relativePath;

        if(!is_dir($dir)){
            return "";
        }

        $files = scandir($dir);

        if($files === false){
            return "";
        }

        $candidates = [];

        foreach ($files as $file){
            if($file === "." || $file === ".." || !str_ends_with($file, "." . $extension)){
                continue;
            }

            if(str_contains($file,$key)){
                return $file;
            }

            $candidates[] = $file;
        }

        return $candidates[0] ?? "";
    }

    private static function deleteFiles(string $path){
        
        $files = scandir($path);
        foreach ($files as $file){
            
            if($file == "." || $file == ".."){
                continue;
            }

            if(!is_dir($path.DIRECTORY_SEPARATOR.$file))
                unlink($path.DIRECTORY_SEPARATOR.$file);
        }
    }

    private static function getFiles(CSS|JS &$minifier,string $path,array $filesConfig = []){

        if(!is_dir($path)){
            return;
        }

        $files = scandir($path);

        if($files === false){
            return;
        }

        sort($files, SORT_STRING);

        foreach ($files as $file){

            if($file == "." || $file == ".."){
                continue;
            }

            $fullPath = $path.DIRECTORY_SEPARATOR.$file;

            // O teste de extensão precisa vir depois do teste de diretório:
            // nomes de pasta não contêm ".js"/".css", então o continue
            // descartava toda subpasta antes de chegar à recursão.
            if(is_dir($fullPath)){
                self::getFiles($minifier,$fullPath,$filesConfig);
                continue;
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if(!in_array($extension, ['js','css'], true)){
                continue;
            }

            if($filesConfig && !in_array($file,$filesConfig,true)){
                continue;
            }

            $minifier->add($fullPath);
        }
    }
}
