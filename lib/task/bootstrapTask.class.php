<?php
/**
 * bootstraps a symfony application, with some action:
 *   install/upgrade plugins
 *   run other symfony tasks
 *   do some shell commandos
 * @see config/bootstrap.yml its self explaining
 * @author Robert Schönthal caziel[at]gmx[dot]net
 * @version 1.0
 */
class bootstrapTask extends sfBaseTask
{
  private $conf;

  protected function configure(){

    $this->addOptions(array(
      new sfCommandOption('conf', null, sfCommandOption::PARAMETER_OPTIONAL, 'The configuration file', 'bootstrap.yml'),
      new sfCommandOption('do', null, sfCommandOption::PARAMETER_OPTIONAL, 'the actions to run', 'all'),
    ));

    $this->namespace        = 'project';
    $this->name             = 'bootstrap';
    $this->briefDescription = 'bootstraps the project with plugins,tasks and shell commandos';
    $this->detailedDescription = <<<EOF
The [bootstrap|INFO] task installs/upgrades plugins, runs tasks and exeuctes shell commandos.
Call it with:
Call it with:

  [php symfony bootstrap|INFO]
EOF;
  }

  /**
   *  execute the task
   * @param <array> $arguments
   * @param <array> $options
   */
  protected function execute($arguments = array(), $options = array()){
    $conf_file = sfConfig::get("sf_config_dir").DIRECTORY_SEPARATOR.$options["conf"];
    $actions = $options["do"]!="all" ? explode(",",$options["do"]) : $options["do"];

    #create config file if not exists
    if(!file_exists($conf_file)){
      $this->createConfFile($conf_file);
    }
    $this->conf = sfYaml::load($conf_file);

    #installs/upgrades plugins
    if($actions=="all" || in_array("plugins",$actions)){
      $this->processPlugins();
    }

    #runs some symfony tasks
    if($actions=="all" || in_array("tasks",$actions)){
      $this->runAdditionalTasks();
    }

    #do some shell calls
    if($actions=="all" || in_array("shells",$actions)){
      $this->doShellCalls();
    }
  }

  /**
   * processes the defined plugins, installs or upgrades them
   * --do=plugins
   */
  private function processPlugins(){
    foreach((array)$this->conf["plugins"] as $uri=>$plugins){
      if(is_array($plugins)){
        foreach($plugins as $plugin){
          if (file_exists(sfConfig::get('sf_plugins_dir').DIRECTORY_SEPARATOR.$plugin)){
            //plugin exists, so upgrade
            $this->upgradePlugin($uri,$plugin);
          }else{
            //plugin does not exists, so install
            $this->installPlugin($uri,$plugin);
          }
        }
      }
    }
  }

  /**
   * installs a plugin
   * @param <string> $uri
   * @param <string> $plugin
   */
  private function installPlugin($uri,$plugin){
    try{
      #add channel if plugin does not exists, we use this unless we have list-channels task
      if(!$uri=="package"){
        $channel_task = new sfPluginAddChannelTask($this->dispatcher, $this->formatter);
        $channel_task->run(array($uri));
        unset($channel_task);
      }

      $install_task = new sfPluginInstallTask($this->dispatcher, $this->formatter);
      if($uri != "package"){
        $install_task->run(array($plugin),array("channel=".$uri,"install_deps"));
      }else{
        $plugin = $this->rewriteTokenString($plugin);
        $install_task->run(array($plugin),array("install_deps"));
      }
      unset($install_task);
    }catch(Exception $e){
      $this->logSection("plugin",$e->getMessage(),null,'ERROR');
    }
  }

  /**
   * upgrades a plugin
   * @param <string> $uri
   * @param <string> $plugin
   */
  private function upgradePlugin($uri,$plugin){
    try{
      $upgrade_task = new sfPluginUpgradeTask($this->dispatcher, $this->formatter);
      if($uri != "package"){
        $upgrade_task->run(array($plugin));
      }else{
        $plugin = $this->rewriteTokenString($plugin);
        $upgrade_task->run(array($plugin),array("install_deps"));
      }
      unset($upgrade_task);
    }catch(Exception $e){
      $this->logSection("plugin",$e->getMessage(),null,'ERROR');
    }
  }

  /**
   * runs additional symfony tasks as defined in bootstrap.yml run_sf_commands
   * --do=tasks
   */
  private function runAdditionalTasks(){
    $cmd_mgr = new sfSymfonyCommandApplication($this->dispatcher,$this->formatter,array("symfony_lib_dir"=>sfConfig::get("sf_symfony_lib_dir")));
    foreach((array)$this->conf["tasks"] as $task){
      try{
        $_task = $cmd_mgr->getTask($task); //find the task by name
        $_task->run(); //run the task
        unset($_task);
      }catch(Exception $e){
        $this->logSection("TASK",$e->getMessage(),null,'ERROR');
      }
    }
  }

  /**
   * make system calls
   * --do=shells
   */
  private function doShellCalls(){
    $fs = new sfFilesystem();
    foreach((array)$this->conf["system"] as $cmd){
      $cmd = $this->rewriteTokenString($cmd);
      try{
        $fs->sh($cmd);
      }catch(Exception $e){
        #cut off not neccesary messages
        $msg = str_replace("Problem executing command ","",$e->getMessage());
        $msg = str_replace("\n","",$msg);

        $this->logSection("exec",$msg,null,'ERROR');
//        sfContext::getInstance()->getLogger()->log(date("Y-m-d H:i",time())." : ".$msg);
      }
    }
  }

  /**
   * creates the config file
   * @param <string> $file
   */
  private function createConfFile($file){
    sfFilesystem::touch($file);
    $content = <<<EOF
plugins:
# your plugins here
# "channel":
#   - plugin
# "package":
#   - path/to/plugin
tasks:
  - cc
# your tasks here
# -task
system:
# - system call
EOF;
    $handle = fopen($file, "w");
    fwrite($handle,$content);
    fclose($handle);
  }

  /**
   * replaces all sf tokens with their values in a string
   * @param <string> $string
   * @param <string> $delimiter
   * @return <string>
   */
  private function rewriteTokenString($string,$delimiter = '%'){
    $search = array_keys(array_change_key_case(sfConfig::getAll(),CASE_UPPER));
    $replace = array_values(sfConfig::getAll());
    $string = str_replace($search,$replace,$string);
    $string = str_replace($delimiter,"",$string);
    return $string;
  }
}
