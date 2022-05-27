<?php

namespace App\src;

use App\modules\admin\dao\mysql\loginDAO;
use App\modules\admin\dao\mysql\moduleDAO;
use App\modules\admin\dao\mysql\logoDAO;
use App\modules\admin\dao\mysql\vocabularyDAO;
use App\modules\admin\dao\mysql\emailServerDAO;
use App\modules\admin\dao\mysql\featureDAO;
use App\modules\helpdezk\dao\mysql\ticketDAO;
use App\modules\admin\dao\mysql\trackerDAO;
use App\modules\admin\dao\mysql\holidayDAO;
use App\modules\helpdezk\dao\mysql\expireDateDAO;

use App\modules\admin\models\mysql\logoModel;
use App\modules\admin\models\mysql\moduleModel;
use App\modules\admin\models\mysql\vocabularyModel;
use App\modules\admin\models\mysql\emailServerModel;
use App\modules\admin\models\mysql\emailSettingsModel;
use App\modules\helpdezk\models\mysql\ticketModel;
use App\modules\admin\models\mysql\trackerModel;
use App\modules\admin\models\mysql\holidayModel;
use App\modules\helpdezk\models\mysql\expireDateModel;

use App\modules\admin\src\loginServices;
use App\src\localeServices;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception; 

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

class appServices
{
    /**
     * @var object
     */
    protected $applogger;
    
    /**
     * @var object
     */
    protected $appEmailLogger;

    /**
     * @var string
     */
    protected $saveMode;

    /**
     * @var string
     */
    protected $imgDir;

    /**
     * @var string
     */
    protected $imgBucket;

    public function __construct()
    {
        // create a log channel
        $formatter = new LineFormatter(null, $_ENV['LOG_DATE_FORMAT']);
        
        $stream = $this->_getStreamHandler();
        $stream->setFormatter($formatter);


        $this->applogger  = new Logger('helpdezk');
        $this->applogger->pushHandler($stream);
        
        // Clone the first one to only change the channel
        $this->appEmailLogger = $this->applogger->withName('email');

        // Setting up the save mode of files
        $this->saveMode = $_ENV['S3BUCKET_STORAGE'] ? "aws-s3" : 'disk';
        if($this->saveMode == "aws-s3"){
            $bucket = $_ENV['S3BUCKET_NAME'];
            $this->imgDir = "logos/";
            $this->imgBucket = "https://{$bucket}.s3.amazonaws.com/logos/";
        }else{
            if($_ENV['EXTERNAL_STORAGE']) {
                $this->imgDir = $this->_setFolder($_ENV['EXTERNAL_STORAGE_PATH'].'/logos/');
                $this->imgBucket = $_ENV['EXTERNAL_STORAGE_URL'].'logos/';
            } else {
                $storageDir = $this->_setFolder($this->_getHelpdezkPath().'/storage/');
                $upDir = $this->_setFolder($storageDir.'uploads/');
                $this->imgDir = $this->_setFolder($upDir.'logos/');
                $this->imgBucket = $_ENV['HDK_URL']."/storage/uploads/logos/";
            }
        }

    }

    public function _getHelpdezkVersion(): string
    {
        // Read the version.txt file
        $versionFile = $this->_getHelpdezkPath() . "/version.txt";

        if (is_readable($versionFile)) {
            $info = file_get_contents($versionFile, FALSE, NULL, 0, 50);
            if ($info) {
                return trim($info);
            } else {
                return '1.0';
            }
        } else {
            return '1.0';
        }

    }

    public function _getHelpdezkPath()
    {
        $pathInfo = pathinfo(dirname(__DIR__));
        return $pathInfo['dirname'];
    }
    
    public function _getPath()
    {
        $docRoot = filter_input(INPUT_SERVER, 'DOCUMENT_ROOT');
        $dirName = str_replace("\\","/",dirname(__DIR__,PATHINFO_BASENAME));
        //The following code snippet is used to resolve the default path in virtual host
        $path_default = ($docRoot == $dirName) ? "" : str_replace($docRoot,'',$dirName);
        
        if (!empty($path_default) && substr($path_default, 0, 1) != '/') {
            $path_default = '/' . $path_default;
        }

        if ($path_default == "/..") {
            $path = "";
        } else {
            $path = $path_default;
        }
        
        return $path;
    }
    
    public function _getLayoutTemplate()
    {
        return $this->_getHelpdezkPath().'/app/modules/main/views/layout.latte';
    }
    
    public function _getNavbarTemplate()
    {
        return $this->_getHelpdezkPath().'/app/modules/main/views/nav-main.latte';
    }
    
    public function _getFooterTemplate()
    {
        return $this->_getHelpdezkPath().'/app/modules/main/views/footer.latte';
    }
    
    public function _getDefaultParams(): array
    {
        $loginSrc = new loginServices();
        $aHeader = $this->_getHeaderData();

        return array(
            "path"			    => $this->_getPath(),
            "lang_default"	    => $_ENV["DEFAULT_LANG"],
            "layout"		    => $this->_getLayoutTemplate(),
            "version" 		    => $this->_getHelpdezkVersion(),
            "navBar"		    => $this->_getNavbarTemplate(),
            "footer"		    => $this->_getFooterTemplate(),
            "demoVersion" 	    => empty($_ENV['DEMO']) ? 0 : $_ENV['DEMO'], // Demo version - Since January 29, 2020
            "isroot"            => ($_SESSION['SES_COD_USUARIO'] == 1) ? true : false,
            "hasadmin"          => ($_SESSION['SES_TYPE_PERSON'] == 1 && $_SESSION['SES_COD_USUARIO'] != 1) ? true : false,
            "navlogin"          => ($_SESSION['SES_COD_USUARIO'] == 1) ? $_SESSION['SES_NAME_PERSON'] : $_SESSION['SES_LOGIN_PERSON'],
            "adminhome"         => $_ENV['HDK_URL'].'/admin/home/index',
            "adminlogo"         => $this->imgBucket.'adm_header.png',
            "hashelpdezk"       => $loginSrc->_isActiveHelpdezk(),
            "helpdezkhome"      => $_ENV['HDK_URL'].'/helpdezk/home/index',
            "hdklogo"           => $aHeader['image'],
            "logout"            => $_ENV['HDK_URL'].'/main/home/logout',
            "id_mask"           => $_ENV['ID_MASK'],
            "ein_mask"          => $_ENV['EIN_MASK'],
            "zip_mask"          => $_ENV['ZIP_MASK'],
            "phone_mask"        => $_ENV['PHONE_MASK'],
            "cellphone_mask"    => $_ENV['CELLPHONE_MASK'],
            "mascdatetime"      => str_replace('%', '', "{$_ENV['DATE_FORMAT']} {$_ENV['HOUR_FORMAT']}"),
            "mascdate"          => str_replace('%', '', $_ENV['DATE_FORMAT']),
            "timesession"       => (!$_SESSION['SES_TIME_SESSION']) ? 600 : $_SESSION['SES_TIME_SESSION'],
            "modules"           => (!isset($_SESSION['SES_COD_USUARIO'])) ? array() :$this->_getModulesByUser($_SESSION['SES_COD_USUARIO']),
            "modalUserSettings" => $this->_getUserSettingsTemplate(),
            "vocabulary"     => $this->_loadVocabulary()
        );
    }

    public function _getHelpdezkVersionNumber()
    {
        $exp = explode('-', $this->_getHelpdezkVersion());
        return $exp[2];
    }

    public function _getActiveModules()
    {
        $moduleDAO = new moduleDAO();
        $moduleModel = new moduleModel();

        $activeModules = $moduleDAO->fetchActiveModules($moduleModel);
        return ($activeModules['status']) ? $activeModules['push']['object']->getActiveList() : false;

    }

    /**
     * Returns header's logo data
	 * 
     * @return array header's logo data (path, width, height)
     */
	public function _getHeaderData(): array 
    {
        $logoDAO = new logoDao(); 
        $logoModel = new logoModel();

        $logoModel->setName("header");
        $logo = $logoDAO->getLogoByName($logoModel);
		
        if(!$logo['status']){ //(empty($objLogo->getFileName()) or !){
            $image 	= $this->imgBucket . 'default/header.png';
			$width 	= "227";
			$height = "70";
        }else{
            $objLogo = $logo['push']['object'];            
            
            if($this->saveMode == 'disk'){
                $pathLogoImage = $this->imgDir . $objLogo->getFileName();
                $st = file_exists($pathLogoImage) ? true : false;
            }elseif($this->saveMode == "aws-s3"){
                $pathLogoImage = $this->imgBucket . $objLogo->getFileName();
                $st = (@fopen($pathLogoImage, 'r')) ? true : false; 
            }

            if(!$st){
                $image 	= $this->imgBucket . 'default/header.png';
                $width 	= "227";
                $height = "70";
            }else{
                $image 	= $this->imgBucket . $objLogo->getFileName();
			    $width 	= $objLogo->getWidth();
			    $height = $objLogo->getHeight();
            }
		}
        
        $aRet = array(
            'image'  => $image,
            'width'  => $width,
            'height' => $height
        );
        
		return $aRet;
    }

	
	/**
	 * en_us Returns an array with module data for the side menu
     *
     * pt_br Retorna um array com os dados dos módulos para o menu lateral
	 *
	 * @param  int $userID
	 * @return array
	 */
	public function _getModulesByUser(int $userID): array 
    {
        $aRet = [];
		$moduleDAO = new moduleDao();
        $moduleModel = new moduleModel();
        $moduleModel->setUserID($userID);
        
        $retModule = $moduleDAO->fetchExtraModulesPerson($moduleModel);
        if($retModule['status']){
            $aModule = $retModule['push']['object']->getActiveList();
            foreach($aModule as $k=>$v) {
                $prefix = $v['tableprefix'];
                if(!empty($prefix)) {
                    $moduleModel->setTablePrefix($prefix);
                    $retSettings = $moduleDAO->fetchConfigDataByModule($moduleModel);
                    if ($retSettings['status']){
                        $modSettings = $retSettings['push']['object']->getSettingsList();
                        $aRet[] = array(
                            'idmodule' => $v['idmodule'],
                            'path' => $v['path'],
                            'class' => $v['class'],
                            'headerlogo' => $this->imgBucket.$v['headerlogo'],
                            'reportslogo' => $v['reportslogo'],
                            'varsmarty' => $v['smarty']
                        );
                    }
                }
            }
        }else{
            return array();
        }
        
        return $aRet;
    }

    /**
     * en_us Check if the user is logged in
     *
     * pt_br Verifica se o usuário está logado
     *
     * @param  mixed $mob
     * @return void
     * 
     * @since November 03, 2017
     */
    public function _sessionValidate($mob=null) {
        if (!isset($_SESSION['SES_COD_USUARIO'])) {
            if($mob){
                echo 1;
            }else{
                $this->_sessionDestroy();
                header('Location:' . $_ENV['HDK_URL'] . '/admin/login');
            }
        }
    }
        
    /**
     * en_us Clear the session variable
     *
     * pt_br Limpa a variável de sessão
     * 
     * @return void
     * 
     * @since November 03, 2017
     */
    public function _sessionDestroy()
    {
        session_start();
        session_unset();
        session_destroy();
    }
    
    /**
     * en_us Return calendar settings
     *
     * pt_br Retorna as configurações do calendário
     *
     * @param array $params Array with others default parameters
     * @return array
     */
    public function _datepickerSettings($params): array
    {
        
        switch ($_ENV['DEFAULT_LANG']) {
            case 'pt_br':
                $params['dtpFormat'] = "dd/mm/yyyy";
                $params['dtpLanguage'] = "pt-BR";
                $params['dtpAutoclose'] = true;
                $params['dtpOrientation'] = "bottom auto";
                $params['dtpickerLocale'] = "bootstrap-datepicker.pt-BR.min.js";
                $params['dtSearchFmt'] = 'd/m/Y';
                break;
            case 'es_es':
                $params['dtpFormat'] = "dd/mm/yyyy";
                $params['dtpLanguage'] = "es";
                $params['dtpAutoclose'] = true;
                $params['dtpOrientation'] = "bottom auto";
                $params['dtpickerLocale'] = "bootstrap-datepicker.es.min.js";
                $params['dtSearchFmt'] = 'd/m/Y';
                break;
            default:
                $params['dtpFormat'] = "mm/dd/yyyy";
                $params['dtpAutoclose'] = true;
                $params['dtpOrientation'] = "bottom auto";
                $params['dtpickerLocale'] = "";
                $params['dtSearchFmt'] = 'm/d/Y';
                break;

        }

        return $params;
    }
    
    /**
     * en_us Create the token to prevent sql injection and xss attacks
     * 
     * pt_br Cria o token para prevenir ataques sql injection e xss
     *
     * @return string
     */
    public function _makeToken(): string
    {
        $token =  hash('sha512',rand(100,1000));
        $_SESSION['TOKEN'] =  $token;
        return $token;
    }

    /**
     * en_us Get the token written to the session variable
     * 
     * pt_br Obtem o token gravado na variável de sessão
     *
     * @return string
     */
    public function _getToken(): string
    {
        session_start();
        return $_SESSION['TOKEN'];

    }

    /**
     * en_us Compares the token sent by the form with the one in the session variable
     * 
     * pt_br Compara o token enviado pelo formulário com o existente na variável de sessão
     *
     * @return bool
     */
    public function _checkToken(): bool
    {

        if (empty($_POST) || empty($_GET) ) {
            return false;
        } else {
            if($_POST['_token'] == $this->_getToken() || $_GET['_token'] == $this->_getToken()) {
                return true;
            }
        }

        return false;
    }

    /**
     * en_us Format a date to write to BD
     * 
     * pt_br Formata uma data para gravar no BD
     *
     * @return string
     */
    public function _formatSaveDate($date): string
    {
        $date = str_replace("/","-",$date);
        
        return date("Y-m-d",strtotime($date));
    }
    
    /**
     * en_us Format a date to view on screen
     * 
     * pt_br Formata uma data para visualizar em tela
     *
     * @param  mixed $date
     * @return string
     */
    public function _formatDate(string $date): string
    {
        $date = str_replace("/","-",$date);
        
        return date($_ENV["SCREEN_DATE_FORMAT"],strtotime($date));
    }


    /**
     * Returns an array with ID and name of search options
     *
     * @return array
     */
    public function _comboFilterOpts(): array
    {
        $translator = new localeServices();

        $aRet = array(
            array("id" => 'eq',"text"=>$translator->translate('equal')), // equal
            array("id" => 'ne',"text"=>$translator->translate('not_equal')), // not equal
            array("id" => 'lt',"text"=>$translator->translate('less')), // less
            array("id" => 'le',"text"=>$translator->translate('less_equal')), // less or equal
            array("id" => 'gt',"text"=>$translator->translate('greater')), // greater
            array("id" => 'ge',"text"=>$translator->translate('greater_equal')), // greater or equal
            array("id" => 'bw',"text"=>$translator->translate('begin_with')), // begins with
            array("id" => 'bn',"text"=>$translator->translate('not_begin_with')), //does not begin with
            array("id" => 'in',"text"=>$translator->translate('in')), // is in
            array("id" => 'ni',"text"=>$translator->translate('not_in')), // is not in
            array("id" => 'ew',"text"=>$translator->translate('end_with')), // ends with
            array("id" => 'en',"text"=>$translator->translate('not_end_with')), // does not end with
            array("id" => 'cn',"text"=>$translator->translate('contain')), // contains
            array("id" => 'nc',"text"=>$translator->translate('not_contain')), // does not contain
            array("id" => 'nu',"text"=>$translator->translate('is_null')), //is null
            array("id" => 'nn',"text"=>$translator->translate('is_not_null'))  // is not null
        );
        
        return $aRet;
    }

    /**
     * Check column name of search
     *
     * @param  mixed $dataIndx
     * @return void
     */
    public function _isValidColumn($dataIndx){
        
        if (preg_match('/^[a-z,A-Z]*$/', $dataIndx))
        {
            return true;
        }
        else
        {
            return false;
        }    
    }
    
    /**
     * Returns rows offset for pagination
     *
     * @param  mixed $pq_curPage
     * @param  mixed $pq_rPP
     * @param  mixed $total_Records
     * @return void
     */
    public function _pageHelper(&$pq_curPage, $pq_rPP, $total_Records){
        $skip = ($pq_curPage > 0) ? ($pq_rPP * ($pq_curPage - 1)) : 0;

        if ($skip >= $total_Records)
        {        
            $pq_curPage = ceil($total_Records / $pq_rPP);
            $skip = ($pq_curPage > 0) ? ($pq_rPP * ($pq_curPage - 1)) : 0;
        }    
        return $skip;
    }

    /**
     * Returns the sql sintax, according filter sended by grid
     *
     * @param string $oper Name of the PqGrid operation
     * @param string $column Field to search
     * @param string $search Column to search
     * @return bool|string    False is not exists operation
     *
     */
    public function _formatGridOperation($oper, $column, $search)
    {
        switch ($oper) {
            case 'eq' : // equal
                $ret = "pipeLatinToUtf8(" . $column . ")" . ' = ' . "pipeLatinToUtf8('" . $search . "')";
                break;
            case 'ne': // not equal
                $ret = "pipeLatinToUtf8(" . $column . ")" . ' != ' . "pipeLatinToUtf8('" . $search . "')";
                break;
            case 'lt': // less
                $ret = $column . ' < ' . $search;
                break;
            case 'le': // less or equal
                $ret = $column . ' <= ' . $search;
                break;
            case 'gt': // greater
                $ret = $column . ' > ' . $search;
                break;
            case 'ge': // greater or equal
                $ret = $column . ' >= ' . $search;
                break;
            case 'bw': // begins with
                $search = str_replace("_", "\_", $search);
                $ret = "pipeLatinToUtf8(" . $column . ")" . ' LIKE ' . "pipeLatinToUtf8('" . $search . '%' . "')";
                break;
            case 'bn': //does not begin with
                $ret = "pipeLatinToUtf8(" . $column . ")" . ' NOT LIKE ' . "pipeLatinToUtf8('" . $search . '%' . "')";
            case 'in': // is in
                $ret = "pipeLatinToUtf8(" . $column . ")" . ' IN (' . "pipeLatinToUtf8('" . $search . "')" . ')';
                break;
            case 'ni': // is not in
                $ret = "pipeLatinToUtf8(" . $column . ")" . ' NOT IN (' . "pipeLatinToUtf8('" . $search . "')" . ')';
                break;
            case 'ew': // ends with
                $search = str_replace("_", "\_", $search);
                $ret = "pipeLatinToUtf8(" . $column . ")" . ' LIKE ' . "pipeLatinToUtf8('" . '%' . rtrim($search) . "')";
                break;
            case 'en': // does not end with
                $search = str_replace("_", "\_", $search);
                $ret = "pipeLatinToUtf8(" . $column . ")" . ' NOT LIKE ' . "pipeLatinToUtf8('" . '%' . rtrim($search) . "')";
                break;
            case 'cn': // contains
                $search = str_replace("_", "\_", $search);
                $ret = "pipeLatinToUtf8(" . $column . ")" . ' LIKE ' . "pipeLatinToUtf8('" . '%' . $search . '%' . "')";
                break;
            case 'nc': // does not contain
                $search = str_replace("_", "\_", $search);
                $ret = "pipeLatinToUtf8(" . $column . ")" . ' NOT LIKE ' . "pipeLatinToUtf8('" . '%' . $search . '%' . "')";
                break;
            case 'nu': //is null
                $ret = $column . ' IS NULL';
                break;
            case 'nn': // is not null
                $ret = $column . ' IS NOT NULL';
                break;
            default:
                die('Operator invalid in grid search !!!' . " File: " . __FILE__ . " Line: " . __LINE__);
                break;
        }

        return $ret;
    }

    /**
     * en_us Format a date to write to BD
     * 
     * pt_br Formata uma data para gravar no BD
     *
     * @return object
     */
    public function _getStreamHandler()
    { 
        switch($_ENV['LOG_LEVEL']){
            case 'INFO':
                $stream = new StreamHandler($this->_getHelpdezkPath() ."/". $_ENV['LOG_FILE'], Logger::INFO);
                break;
            case 'NOTICE':
                $stream = new StreamHandler($this->_getHelpdezkPath() ."/". $_ENV['LOG_FILE'], Logger::NOTICE);
                break;
            case 'WARNING':
                $stream = new StreamHandler($this->_getHelpdezkPath() ."/". $_ENV['LOG_FILE'], Logger::WARNING);
                break;
            case 'ERROR':
                $stream = new StreamHandler($this->_getHelpdezkPath() ."/". $_ENV['LOG_FILE'], Logger::ERROR);
                break;
            case 'CRITICAL':
                $stream = new StreamHandler($this->_getHelpdezkPath() ."/". $_ENV['LOG_FILE'], Logger::CRITICAL);
                break;
            case 'ALERT':
                $stream = new StreamHandler($this->_getHelpdezkPath() ."/". $_ENV['LOG_FILE'], Logger::ALERT);
                break;
            case 'EMERGENCY':
                $stream = new StreamHandler($this->_getHelpdezkPath() ."/". $_ENV['LOG_FILE'], Logger::EMERGENCY);
                break;
            default:
                $stream = new StreamHandler($this->_getHelpdezkPath() ."/". $_ENV['LOG_FILE'], Logger::DEBUG);
                break;
        }
        
        return $stream;
    }
    
    /**
     * en_us Checks if the directory exists, if not, it will be created. 
     *       It also checks if you have write permissions, if not, grant the corresponding permissions.
     * 
     * pt_br Verifica se o diretório existe, caso não exista, será criado. 
     *       Também verifica se tem permissões de escrita, caso não possua, concede as permissões correspondentes
     *
     * @param  mixed $path
     * @return string
     */
    public function _setFolder(string $path): string
    {
        if(!is_dir($path)) {
            $this->applogger->info("Directory: $path does not exists, I will try to create it.",['Class' => __CLASS__, 'Method' => __METHOD__]);
            if (!mkdir ($path, 0777 )) {
                $this->applogger->error("I could not create the directory: $path",['Class' => __CLASS__, 'Method' => __METHOD__]);
                return false;
            }
        }

        if (!is_writable($path)) {
            $this->applogger->info('Directory: '. $path.' is not writable, I will try to make it writable',['Class' => __CLASS__, 'Method' => __METHOD__]);
            if (!chmod($path,0777)){
                $this->applogger->error("Directory: $path is not writable!!",['Class' => __CLASS__, 'Method' => __METHOD__]);
                return false;
            }
        }

        return $path;
    }

    public function _makeMenuByModule($moduleModel)
    {
        $moduleDAO = new moduleDAO();
        $retCategories = $moduleDAO->fetchModuleActiveCategories($moduleModel);
        $aCategories = array();
        
        if($retCategories['status']){
            $categoriesObj = $retCategories['push']['object'];
            $categories = $categoriesObj->getCategoriesList();
            
            foreach($categories as $ck=>$cv) {
                $categoriesObj->setCategoryID($cv['category_id']);
                
                $retPermissions = $moduleDAO->fetchPermissionMenu($categoriesObj);
                
                if($retPermissions['status']){
                    $permissionsObj = $retPermissions['push']['object'];
                    $permissionsMod = $permissionsObj->getPermissionsList();
                    
                    foreach($permissionsMod as $permidx=>$permval) {
                        $allow = $permval['allow'];
                        $path  = $permval['path'];
                        $program = $permval['program'];
                        $controller = $permval['controller'];
                        $prsmarty = $permval['pr_smarty'];

                        $checkbar = substr($permval['controller'], -1);
                        if($checkbar != "/") $checkbar = "/";
                        else $checkbar = "";

                        $controllertmp = ($checkbar != "") ? $controller : substr($controller,0,-1);
                        $controller_path = 'app/modules/'. $path  .'/controllers/' . ucfirst($controllertmp)  . '.php';
                        
                        if (!file_exists($controller_path)) {
                            $this->applogger->error("The controller does not exist: {$controller_path}", ['Class' => __CLASS__,'Method' => __METHOD__,'Line' => __LINE__]);
                        }else{
                            if ($allow == 'Y') {
                                $aCategories[$cv['cat_smarty']][$prsmarty] = array("url"=>$_ENV['HDK_URL'] . "/".$path."/" . $controller . $checkbar."index", "program_name"=>$prsmarty);
                            }
                        }
                    }
                }
            }
        }
        
        return $aCategories;

    }
    
    /**
     * Returns module's ID
     *
     * @param  string $moduleName
     * @return int
     */
    public function _getModuleID(string $moduleName): int
    {
        $moduleDAO = new moduleDAO();
        $moduleModel = new moduleModel();

        $moduleModel->setName($moduleName);
        $ret = $moduleDAO->getModuleInfoByName($moduleModel);
        return ($ret['status']) ? $ret['push']['object']->getIdModule() : 0;
    }

    /**
     * Returns the image file format( Only allowed formats: GIF, PNG, JPEG ans BMP)
     *
     * Used for some cases where you can upload various formats and at the time of showing,
     * we do not know what format it is in. The method tests if the file exists and verifies
     * that the format is compatible
     *
     * @param string $target Image file
     * @return boolean|string    False is not exists ou file extention
     *
     * @author Rogerio Albandes <rogerio.albandes@helpdezk.cc>
     */
    public function _getImageFileFormat($target)
    {
        $target = $target . '.*';
        
        $arrImages = glob($target);

        if (empty($arrImages))
            return false;
       
        foreach ($arrImages as &$imgFile) {
            if (in_array(exif_imagetype($imgFile), array(IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP))) {
                switch (exif_imagetype($imgFile)) {
                    case 1:
                        $ext = 'gif';
                        break;
                    case 2:
                        $ext = 'jpg';
                        break;
                    case 3:
                        $ext = 'png';
                        break;
                    case 6:
                        $ext = 'bmp';
                }
                return $ext;
            }
        }
        return false;
    }

    public function _getUserSettingsTemplate()
    {
        return $this->_getHelpdezkPath().'/app/modules/main/views/modals/main/modal-user-settings.latte';
    }

    /**
     * Setup vocabulary
     *
     * @return array
     */
    public function _loadVocabulary(): array
    {
        $vocabDAO = new vocabularyDAO();
        $vocabModel = new vocabularyModel();
        $aRet = array();

        $ret = $vocabDAO->queryVocabularies("AND UPPER(b.name) = UPPER('{$_ENV['DEFAULT_LANG']}')",null,"ORDER BY key_name");

        if($ret['status']){
            $vocabularies = $ret['push']['object']->getGridList();

            foreach($vocabularies as $k=>$v){
                $aRet[$v['key_name']] = $v['key_value'];
            }
            
        }
        
        return $aRet;
    }

    /**
     * en_us Format a date to write to BD
     * 
     * pt_br Formata uma data para gravar no BD
     *
     * @return string
     */
    public function _formatSaveDateHour($dateHour): string
    {
        $dateHour = str_replace("/","-",$dateHour);
        
        return date("Y-m-d H:i:s",strtotime($dateHour));
    }
    
    /**
     * en_us Send email
     * 
     * pt_br Envia e-mail 
     *
     * @param  string $type         text here
     * @param  string $serverName   text here
     * @param  array  $params       text here
     * @return array
     */
    public function _sendEmail(array $params,string $type=null,string $serverName=null): array
    {
        $emailSrvDAO = new emailServerDAO();
        $emailSrvModel = new emailServerModel();

        $where = (!$type && !$serverName) ? "WHERE a.default = 'Y'" : "WHERE b.name = '{$type}' AND a.name = '{$serverName}'";
        $ret = $emailSrvDAO->queryEmailServers($where);

        if(!$ret['status']){
            $this->applogger->error("No result returned",['Class' => __CLASS__,'Method' => __METHOD__,'Line' => __LINE__]);
            return array('status'=>false,"message"=>$ret['push']['message']);
        }

        $aEmailSrv = $ret['push']['object']->getGridList();
        $params = array_merge($aEmailSrv[0],$params);

        switch($aEmailSrv[0]['servertype']){
            case "SMTP"://STMP
                $retSend = $this->_sendSMTP($params);
                break;
            case "API"://STMP
                echo "{$aEmailSrv[0]['servertype']}\n";
                break;
            default:
                echo "Case default\n";
                break;

        }
        
        if(!$retSend['status']){
            $this->applogger->error("Error trying send email. Error: {$retSend['message']}",['Class' => __CLASS__,'Method' => __METHOD__,'Line' => __LINE__]);
            return array('status'=>false,"message"=>"{$retSend['message']}");
        }

        return array('status'=>true,"message"=>"");
    }

    /**
     * en_us Send email via SMTP
     * 
     * pt_br Envia e-mail por SMTP
     *
     * @param  array  $params       text here
     * @return array                text here
     */
    public function _sendSMTP(array $params)
    {
        $featureDAO = new featureDAO();
        $emailSettingModel = new emailSettingsModel();

        $ret = $featureDAO->getEmailSettings($emailSettingModel);

        if(!$ret['status']){
            return array("success"=>false,"message"=>"");
        }
        
        $aEmailSrvObj = $ret['push']['object'];

        $mailTitle     = '=?UTF-8?B?'.base64_encode($aEmailSrvObj->getTitle()).'?=';
        $mailMethod    = 'smtp';
        $mailHost      = $params['apiendpoint'];
        $mailDomain    = $aEmailSrvObj->getDomain();
        $mailAuth      = $aEmailSrvObj->getAuth();
        $mailUsername  = $params['user'];
        $mailPassword  = $params['password'];
        $mailSender    = $aEmailSrvObj->getSender();
        $mailHeader    = $aEmailSrvObj->getHeader();
        $mailFooter    = $aEmailSrvObj->getFooter();
        $mailPort      = $params['port'];
        
        $mail = new PHPMailer(true);

        $mail->CharSet = 'utf-8';

        if($params['customHeader'] && $params['customHeader'] != ''){
            $mail->addCustomHeader($params['customHeader']);
        }

        if($_ENV['DEMO']){
            $mail->addCustomHeader('X-hdkLicence:' . 'demo');
        }else{
            $mail->addCustomHeader('X-hdkLicence:' . $_ENV['LICENSE']);
        }

        if($params['sender'] && $params['sender'] != ''){
            $mailSender = $params['sender'];
        }

        if($params['sender_name'] && $params['sender_name'] != ''){
            $mailTitle = '=?UTF-8?B?'.base64_encode($params['sender_name']).'?=';
        }

        $mail->setFrom($mailSender, $mailTitle);

        if($mailHost)
            $mail->Host = $mailHost;

        if(isset($mailPort) AND !empty($mailPort)) {
            $mail->Port = $mailPort;
        }

        $mail->Mailer = $mailMethod;
        $mail->SMTPAuth = $mailAuth;

        if($aEmailSrvObj->getTls())
            $mail->SMTPSecure = 'tls';

        $mail->Username = $mailUsername;
        $mail->Password = $mailPassword;

        $mail->AltBody 	= "HTML";
        $mail->Subject 	= '=?UTF-8?B?'.base64_encode($params['subject']).'?=';

        //$mail->SetLanguage('br', $this->helpdezkPath . "/includes/classes/phpMailer/language/");

        $paramsDone = array("msg" => $params['msg'],
                            "msg2" => $params['msg2'],
                            "mailHost" => $mailHost,
                            "mailDomain" => $mailDomain,
                            "mailAuth" => $mailAuth,
                            "mailPort" => $mailPort,
                            "mailUsername" => $mailUsername,
                            "mailPassword" => $mailPassword,
                            "mailSender" => $mailSender
                            );

        if(sizeof($params['attachment']) > 0){
            foreach($params['attachment'] as $key=>$value){
                $mail->AddAttachment($value['filepath'], $value['filename']);  // optional name
            }
        }

        $normalProcedure = true;

        if((isset($params['tracker']) && $params['tracker']) || (isset($params['tokenOperatorLink']) && $params['tokenOperatorLink'])) {

            $aEmail = $this->_makeArrayTracker($params['address']);
            $body = $mailHeader . $params['contents'] . $mailFooter;

            foreach ($aEmail as $key => $sendEmailTo) {
                $mail->AddAddress($sendEmailTo);

                if($params['tokenOperatorLink']) {
                    $linkOperatorToken = $this->_makeLinkOperatorToken($sendEmailTo, $params['code_request']);
                    if(!$linkOperatorToken){
                        $this->appEmailLogger->error("Error make link operator with token, ticket #{$params['code_request']}",['Class' => __CLASS__,'Method' => __METHOD__,'Line' => __LINE__]);
                    } else {
                        $newContent = $this->_replaceBetweenTags($params['contents'], $linkOperatorToken, 'pipegrep');
                        $body = $mailHeader . $newContent . $mailFooter;
                    }
                }

                if($params['tracker']) {
                    $retTracker = $this->_saveTracker($params['idmodule'],$mailSender,$sendEmailTo,addslashes($params['subject']),addslashes($params['contents']));
                    if(!$retTracker['status']) {
                        $this->appEmailLogger->error("Error insert in tbtracker, {$retTracker['message']}",['Class' => __CLASS__,'Method' => __METHOD__,'Line' => __LINE__]);
                    } else {
                        $idEmail = $retTracker['idEmail'];
                        $trackerID = "<img src='{$_ENV['HDK_URL']}/tracker/{$params['moduleName']}/{$idEmail}.png' height='1' width='1' />";
                        $body = $body . $trackerID;
                    }
                }

                $mail->Body = $body;

                //sent email
                $retSend = $this->_isEmailDone($mail,$paramsDone);

                $mail->ClearAddresses();
            }

            $normalProcedure = false;
        }

        if ($normalProcedure){
            //Checks for more than 1 email address at recipient
            $this->_makeSentTo($mail,$params['address']);
            $mail->Body = $mailHeader . $params['contents'] . $mailFooter;
            // sent email
            $retSend = $this->_isEmailDone($mail,$paramsDone);
        }

        $mail->ClearAttachments();

        if(!$retSend['status'])
            return array("status"=>false,"message"=>"{$retSend['message']}");
        else
            return array("status"=>true,"message"=>"");       
    }
    
    /**
     * en_us Convert a list of email addresses to an array
     * 
     * pt_br Converte em array uma lista de endereços de e-mail
     *
     * @param  string $sentTo
     * @return array
     */
    public function _makeArrayTracker(string $sentTo): array
    {
        $aExist = array();
        $aRet = array();

        if(preg_match("/;/", $sentTo)){
            $aRecipient = explode(";", $sentTo);
            if (is_array($aRecipient)) {
                for ($i = 0; $i < count($aRecipient); $i++) {
                    if (empty($aRecipient[$i]))
                        continue;
                    if (!in_array($aRecipient[$i], $aExist)) {
                        $aExist[] = $aRecipient[$i];
                        array_push($aRet,$aRecipient[$i]);
                    }
                }
            } else {
                array_push($aRet,$aRecipient);
            }
        }else{
            array_push($aRet,$sentTo);
        }

        return $aRet;
    }
    
    /**
     * en_us Create link to view from email sent
     * 
     * pt_br Cria link para visualização a partir do e-mail enviado
     *
     * @param  mixed $recipient     Recipient's email address
     * @param  mixed $ticketCode    Ticket's code
     * @return void
     */
    public function _makeLinkOperatorToken($recipient,$ticketCode)
    {
        $ticketDAO = new ticketDAO();
        $ticketModel = new ticketModel();

        $ticketModel->setRecipientEmail($recipient)
                    ->setTicketCode($ticketCode);

        $ret = $ticketDAO->getUrlTokenByEmail($ticketModel);
        if(!$ret['status']){
            return false;
        }

        $token = $ret['push']['object']->getLinkToken();
        if($token && !empty($token))
            return "<a href='".$_ENV['HDK_URL']."/helpdezk/hdkTicket/viewTicket/{$ticketCode}/{$token}' target='_blank'>{$ticketCode}</a>";
        else
            return false ;
    }

    /**
     * en_us Replace text between tags and delete the tags
     * 
     * pt_br Substitui o texto entre as tags e deleta as tags
     *
     * @author Rogerio Albandes <rogerio.albandes@pipegrep.com.br>
     *
     * @param string $text     Original text
     * @param string $replace  New text
     * @param string $tag      Tag's string
     *
     * @return string           New text without tags
     */
    public function _replaceBetweenTags($text, $newText, $tag)
    {
        return  preg_replace("#(<{$tag}.*?>).*?(</{$tag}>)#", $newText , $text);
    }
    
    /**
     * en_us Replace text between tags and delete the tags
     * 
     * pt_br Substitui o texto entre as tags e deleta as tags
     *
     * @param  mixed $idModule
     * @param  mixed $mailSender
     * @param  mixed $sentTo
     * @param  mixed $subject
     * @param  mixed $body
     * @return array
     */
    function _saveTracker($idModule,$mailSender,$sentTo,$subject,$body): array
    {
        $trackerDAO = new trackerDAO();
        $trackerModel = new trackerModel();
        $trackerModel->setIdModule($idmodule)
                     ->setSender($mailSender)
                     ->setRecipient($senTo)
                     ->setSubject($subject)
                     ->setContent($body);

        $ret = $trackerDAO->insertTracker($trackerModel);
        if(!$ret['status']) {
            return array('status'=>false,'message'=>$ret['push']['message'],'idEmail'=>'');
        } else {
            return array('status'=>false,'message'=>'','idEmail'=>$ret['push']['object']->getIdEmail());
        }

    }
    
    /**
     * en_us Process email sending
     * 
     * pt_br Processa o envio de e-mail
     *
     * @param  object $mail
     * @param  array $params
     * @return array
     */
    public function _isEmailDone($mail,$params){
        try{
            $mail->send();
            $this->appEmailLogger->info("Email Succesfully Sent, {$params['msg']}",['Class' => __CLASS__,'Method' => __METHOD__,'Line' => __LINE__]);
            $aRet = array("status"=>true,"message"=>"");
        }catch(Exception $e){
            $this->appEmailLogger->error("Error send email, {$params['msg']}",['Class' => __CLASS__,'Method' => __METHOD__,'Line' => __LINE__]);
            $this->appEmailLogger->error("Error send email, {$params['msg2']}. Erro: {$mail->ErrorInfo}",['Class' => __CLASS__,'Method' => __METHOD__,'Line' => __LINE__]);
            $this->appEmailLogger->info("Error send email, request # {$params['request']}. HOST: {$params['mailHost']} DOMAIN: {$params['mailDomain']} AUTH: {$params['mailAuth']} PORT: {$params['mailPort']} USER: {$params['mailUserName']} PASS: {$params['mailPassword']} SENDER: {$params['mailSender']}",['Class' => __CLASS__,'Method' => __METHOD__,'Line' => __LINE__]);
            $aRet = array("status"=>false,"message"=>"{$mail->ErrorInfo}");
        }

        return $aRet;
    }
    
    /**
     * en_us Add the e-mail addresses for shipping
     * 
     * pt_br Adiciona os endereços de e-mail para envio
     *
     * @param  object $mail     Object phpmailer
     * @param  string $sentTo   Email's list
     * @return void
     */
    public function _makeSentTo($mail,$sentTo)
    {
        $aExist = array();
        if (preg_match("/;/", $sentTo)) {
            //$this->logIt('Entrou',7,'email');
            $aRecipient = explode(";", $sentTo);
            if (is_array($aRecipient)) {
                for ($i = 0; $i < count($aRecipient); $i++) {
                    // If the e-mail address is NOT in the array, it sends e-mail and puts it in the array
                    // If the email already has the array, do not send again, avoiding duplicate emails
                    if (!in_array($aRecipient[$i], $aExist)) {
                        $mail->AddAddress($aRecipient[$i]);
                        $aExist[] = $aRecipient[$i];
                    }
                }
            } else {
                $mail->AddAddress($aRecipient);
            }
        } else {
            $mail->AddAddress($sentTo);
        }
    }

    public function _sendMandrill($message)
    {
        $dbCommon = new common();
        $emconfigs = $dbCommon->getEmailConfigs();

        $endPoint = $emconfigs['MANDRILL_ENDPOINT'];
        $token = $emconfigs['MANDRILL_TOKEN'];
        $params = array(
            "key" => $token,
            "message" => $message
        );
        
        $headers = [
            "Content-Type: application/json"
        ];
        $ch = curl_init();
        $ch_options = [
            CURLOPT_URL => $endPoint,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POST    => 1,
            CURLOPT_HEADER  => 0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($params)
        ];
        curl_setopt_array($ch,$ch_options);
        $callback = curl_exec($ch);
        $result   = (($callback) ? json_decode($callback,true) : curl_error($ch));
        
        return $result;
            
    }
        
    /**
     * en_us Returns the expiration date
     * 
     * pt_br Retorna a data do expiração
     *
     * @param  mixed $startDate
     * @param  mixed $days
     * @param  mixed $fullday
     * @param  mixed $noWeekend     Include weekends
     * @param  mixed $noHolidays    Include holidays
     * @return void
     */
    public function _getExpireDate($startDate=null,$days=null,$fullday=true,$noWeekend=false,$noHolidays=false,$companyID=null)
    {

        if(!isset($startDate)){$startDate = date("Y-m-d H:i:s");}

        if(!$days){
            $daysSum = "+0 day";
        }elseif($days > 0 or $days == 1){
            $daysSum = "+".$days." day";
        }else{
            $daysSum = "+".$days." days";
        }

        $dataSum = date("Y-m-d H:i:s",strtotime($startDate." ".$daysSum));

        $dateHolyStart = date("Y-m-d",strtotime($startDate)); // Separate only the inicial date to check for holidays in the period
        $dateHolyEnd = date("Y-m-d",strtotime($dataSum)); //Separate only the final date to check for holidays in the period
        $sumDaysHolidays = $this->_getTotalHolidays($dateHolyStart,$dateHolyEnd);
        $sumDaysHolidays = ($sumDaysHolidays) ? $sumDaysHolidays : "0";

        // Add holidays
        $dataSum = ($sumDaysHolidays && $sumDaysHolidays > 1) 
                    ? date("Y-m-d H:i:s",strtotime($dataSum." +".$sumDaysHolidays." days")) 
                    : date("Y-m-d H:i:s",strtotime($dataSum." +".$sumDaysHolidays." day"));
                
        // Working days
        $businessDays = $this->_getBusinessDays();
        if(!$businessDays)
            return false;

        $dateCheckStart = date("Y-m-d",strtotime($startDate));
        $dateCheckEnd = date("Y-m-d",strtotime($dataSum));
        $addNotBussinesDay = 0;
        
        // Non-working days
        while(strtotime($dateCheckStart) <= strtotime($dateCheckEnd)) {
            $numWeek = date('w',strtotime($dateCheckStart));
            if (!array_key_exists($numWeek,$businessDays)) {
                $addNotBussinesDay++;
            }
            $dateCheckStart = date ("Y-m-d", strtotime("+1 day", strtotime($dateCheckStart)));
        }
        
        $dataSum = date("Y-m-d H:i:s",strtotime($dataSum." +".$addNotBussinesDay." days")); // Add non-working days
        $dataCheckBD = $this->_checkValidBusinessDay($dataSum,$businessDays,$companyID);
        if(!$fullday){
            $dataSum = $this->_checkValidBusinessHour($dataCheckBD,$businessDays); // Verify if the time is the interval of service
        }
        
        // If you change the day, check to see if it is a working day
        if(strtotime(date("Y-m-d",strtotime($dataCheckBD))) != strtotime(date("Y-m-d",strtotime($dataSum)))){
            $dataCheckBD = $this->_checkValidBusinessDay($dataSum,$businessDays,$companyID);
            return $dataCheckBD;
        }else{
            return $dataSum;
        }

    }

    public function _checkValidBusinessDay($date,$businessDay,$companyID=null)
    {
        $numWeek = date('w',strtotime($date));

        $i = 0;
        while($i == 0){
            
            while (!array_key_exists($numWeek, $businessDay)) {
                $date = date ("Y-m-d H:i:s", strtotime("+1 day", strtotime($date)));
                $numWeek = date('w',strtotime($date));
            }
            $dateHoly = date("Y-m-d",strtotime($date));

            $daysHoly = $this->_getTotalHolidays($dateHoly,$dateHoly,$companyID);
            if(!$daysHoly)
                $i = 1;
                
            
            if($daysHoly > 0){
                $date = date("Y-m-d H:i:s",strtotime($date." +".$daysHoly." days"));
                $numWeek = date('w',strtotime($date));
            }
        }
        
        return $date;
    }

    public function _checkValidBusinessHour($date,$businessDay){
        $i = 0;
        while($i == 0){
            $numWeek = date('w',strtotime($date));
            $hour = strtotime(date('H:i:s',strtotime($date)));
            $begin_morning = strtotime($businessDay[$numWeek]['begin_morning']);
            $end_morning = strtotime($businessDay[$numWeek]['end_morning']);
            $begin_afternoon = strtotime($businessDay[$numWeek]['begin_afternoon']);
            $end_afternoon = strtotime($businessDay[$numWeek]['end_afternoon']);
            if($hour >= $begin_morning && $hour <= $end_morning){
                $i = 1;
            }
            else if($hour >= $begin_afternoon && $hour <= $end_afternoon){
                $i = 1;
            }
            else{
                $date = date ("Y-m-d H:i:s", strtotime("+1 hour", strtotime($date)));
                $i = 0;
            }
        }
        return $date;
    }

    public function _getTotalHolidays($startDate,$endDate,$companyID=null)
    {
        $holidayDAO = new holidayDAO();
        $holidayModel = new holidayModel();
        $holidayModel->setStartDate($startDate)
                     ->setEndDate($endDate);
        
        $rsNationalDaysHoliday = $holidayDAO->getNationalHolidaysTotal($holidayModel); // Verifies the quantity of holidays in the period
        
        if(!$rsNationalDaysHoliday['status'])
            return false;

        if($companyID){
            $rsNationalDaysHoliday['push']['object']->setIdCompany($companyID);

            $rsCompanyDaysHoliday = $db->getCompanyDaysHoliday($rsNationalDaysHoliday['push']['object']); // Verifies the quantity of company�s holidays in the period
            if(!$rsCompanyDaysHoliday['status'])
                return false;

            $sumDaysHolidays = $rsCompanyDaysHoliday['push']['object']->getTotalNational() + $rsCompanyDaysHoliday['push']['object']->getTotalCompany();
        }else{
            $sumDaysHolidays = $rsNationalDaysHoliday['push']['object']->getTotalNational();
        }
        
        return $sumDaysHolidays;
    }

    public function _getBusinessDays()
    {
        $expireDateDAO = new expireDateDAO();
        $expireDateModel = new expireDateModel();

        $ret = $expireDateDAO->fetchBusinessDays($expireDateModel); // Verifies the quantity of holidays in the period
        
        if(!$ret['status'])
            return false;

        foreach($ret['push']['object']->getBusinessDays() as $k=>$v){
            $businessDay[$v['num_day_week']] = array(
                "begin_morning" 	=> $v['begin_morning'],
                "end_morning" 		=> $v['end_morning'],
                "begin_afternoon" 	=> $v['begin_afternoon'],
                "end_afternoon" 	=> $v['end_afternoon']
            );
        }
        
        return $businessDay;
    }
    
    /**
     * Reduce a string
     *
     * @param  mixed $string The text to reduce
     * @param  mixed $lenght Lenght of new string
     * @return string
     */
    public function _reduceText(string $string, int $lenght): string
    {
        $string = strip_tags($string);
        $string = substr($string, 0, $lenght) . " ...";
        return $string;
    }

    

}