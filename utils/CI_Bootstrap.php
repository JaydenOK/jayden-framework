<?php

/**
 * 引导程序，用来初始化系统需要的各自资源
 */
class CI_Bootstrap
{
    public function __construct()
    {
        $this->init();
    }
    
    /**
     * 初始化方法
     */
    public function init()
    {
        $this->initAutoLoader();
        $CI = &get_instance();
        $CI->eventManager = EventManager::getInstance();
    }
    
    /**
     * 初始化自动加载类
     * @return boolean
     */
    public function initAutoLoader()
    {
        spl_autoload_register(function ($className){
            Bootstrap::_loadClass($className);
        });
        
        spl_autoload_register(function ($className){
            //如果是带命名空间的类名、根据命名空间查找类文件
            if (strpos($className, '\\') != false)
            {
                $className = trim($className, '\\');
                $paths = explode('\\', $className);
                $className = array_pop($paths);
                $fileBasePath = APPPATH . '/../' . implode(DIRECTORY_SEPARATOR, $paths);
                $fileName = ucfirst($className) . '.php';
                $filePath = $fileBasePath . DIRECTORY_SEPARATOR . $fileName;
                if (file_exists($filePath))
                {
                    require_once $filePath;
                    return true;
                }
            }
        });
        
        
        return true;
    }
    
    /**
     * 加载类文件
     * @param unknown $className
     * @param unknown $paths
     * @return boolean
     */
    protected static function _loadClass($className, $paths = [])
    {
        static $modules;
        //如果是CI框架的类，文件名要去掉CI_
        if (strpos($className, 'CI_') !== false)
            $className = str_replace('CI_', '', $className);
        if (strpos($className, 'MY_') !== false)
            $fileName = $className . '.php';
        else
            $fileName = ucfirst($className) . '.php';
        if (!empty($paths) && is_array($paths))
        {
            foreach ($paths as $path)
            {
                $filePath = rtrim(strval($path), DIRECTORY_SEPARATOR) . 
                    DIRECTORY_SEPARATOR . $fileName;
                if (file_exists($filePath))
                {
                    require_once $filePath;
                    return true;
                } 
            }
            return false;
        }
        else
       {
           //在CI框架core目录查找
           $sysCorePath = BASEPATH . 'core' . DIRECTORY_SEPARATOR;
           //在CI框架libraries目录查找
           $sysLibraryPath = BASEPATH . 'libraries' . DIRECTORY_SEPARATOR;
           //在应用core目录查找
           $corePath = APPPATH . 'core' . DIRECTORY_SEPARATOR;
           //在应用models目录查找
           $modelPath = APPPATH . 'models' . DIRECTORY_SEPARATOR;
           //在应用libraries目录查找
           $libraryPath = APPPATH . 'libraries' . DIRECTORY_SEPARATOR;
           $paths = [$sysCorePath, $sysLibraryPath, $corePath, $modelPath, $libraryPath];
           if (Bootstrap::_loadClass($className, $paths)) return true;
           //在modules的models目录里面查找
           $basePath = APPPATH . 'modules' .DIRECTORY_SEPARATOR;
           if (empty($modules))
           {
               $dir = opendir($basePath);
               $modules = [];
               while (($moduleName = readdir($dir)) !== false)
               {
                   if ($moduleName == '.' || $moduleName == '..') continue;
                   $modules[] = $moduleName;
               }
           }
           $modelPaths = [];
           foreach ($modules as $moduleName)
           {
               //查找module下面的models目录
               $modelPaths[] = $basePath . $moduleName . DIRECTORY_SEPARATOR . 'models';
               //查找module下面的services目录
               $modelPaths[] = $basePath . $moduleName . DIRECTORY_SEPARATOR . 'services';
           }
           if (!empty($modelPaths) && Bootstrap::_loadClass($className, $modelPaths)) return true;
        }
        return false;
    }
}