<?php

namespace App\Console\Commands;
use Mail;
use App\Console\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Process\Process;
class Deployer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy:project {action=deploy}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'deploy project';

    protected $basePath = './';
    //设置部署目录
    protected $deploy_path = '/data/';
    //设置部署目录名称
    protected $siteName = 'sites' ;
    //设置部署用户名称
    protected $userName = 'deployer';
    //设置保留版本数量
    protected $keep_releases = 50;
    // 环境对应中文名称
    protected $stageNames = [
        'test'  => "测试",
        'stage' => "仿真",
        'prod'  => "生产",
    ];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // 获取操作类型
        $action = $this->argument('action');

        $this->line('');
        $this->info('***********************');
        $this->info(' 团队项目部署工具  ');
        $this->info('***********************');
        $this->line('');
        $this->line('');
        //获取要部署的项目
        $project = $this->getDeployProject();
        // 如果是回滚操作
        if ($action == 'rollback') {
            $environments = $this->getgetDeployEnvironment($project);

            $stageNames = [];
            $serverNames = [];
            foreach ($environments as $environment){
                if($environment == 'prod') {
                    $this->getDeployProdServer($project);
                }
                if($environment !='prod') {
                    $this->siteName = $this->siteName."_".$environment;
                }
                $stageNames[]= $this->stageNames[$environment];

                //获取要部署的服务器
                $serverNames = array_merge($serverNames, $project['serverNames'][$environment]);
            }
            //获取倒数第二个发布版本
            $releasePath = $this->getHistoryReleasePath($project,$environment,2);
            //修改current地址
            $this->symlink($project,$environment,$releasePath);
            //删除最后一个版本
            $this->delHistoryReleasePath($project,$environment,1);
            $this->info('回滚成功');
            exit;
        }
        //更新代码
        $this->updateDeployProjectCode($project);
        // 获取分支
        $branch = $this->getDeployProjectBranch($project);
        //获取当前分支部署commit
        $commit = $this->getDeployProjectCommit($project,$branch);
        //获取部署环境
        $environments = $this->getgetDeployEnvironment($project);

        $stageNames = [];
        $serverNames = [];
        foreach ($environments as $environment){
            if($environment == 'prod') {
                $this->getDeployProdServer($project);
            }
            $stageNames[]= $this->stageNames[$environment];

            //获取要部署的服务器
            $serverNames = array_merge($serverNames, $project['serverNames'][$environment]);
        }
        $stageName = implode(" ， ", $stageNames);
        $serverName = implode(" ， ", $serverNames);
        //截取commit前７位
        $commit =  mb_substr($commit,0,7);
        $commit_info = $this->get_commit_info($project, $commit);
        $commit_info = str_replace('<br/>', '', $commit_info);
        $this->line('');
        $this->error("\n********请确认以下信息********\n");
        $this->line('');
        $this->info("\n 项目名称：{$project['project_cn_name']} \n");
        $this->line('');
        $this->info("\n   提交版本：{$commit_info} \n");
        $this->line('');
        $this->info("\n   部署环境：{$stageName}\n");
        $this->line('');
        $this->info("\n   部署服务器：{$serverName}\n");
        $this->line('');
        $this->info('******************************');
        $this->line('');
        if (!$this->confirm('请确实以上信息是否正确?')) {
            exit;
        }
        //多环境分别部署开始

        foreach ($environments as $environment){
            $this->siteName = 'sites';
            //处理目录
            if($environment !='prod') {
                $this->siteName = $this->siteName."_".$environment;
            }

            $stageName = $this->stageNames[$environment];
            $this->warn("********开始部署{$stageName}环境********");
            // 获取部署版本路径
            $releasePath = $this->syncProjectCode($project,$environment,$commit);
            //设置共享目录
            $this->shared_dirs($project,$environment,$releasePath);
            //设置共享文件
            $this->shared_files($project,$environment,$releasePath);
            // 设置current软链目录
            $this->symlink($project,$environment,$releasePath);
            // 清除旧版本文件
            $this->cleanup($project,$environment);
            // 发送邮件
            $this->send_email($project,$environment,$commit);
        }
        $this->info("发布成功结束！");

 
    }

    /**
     *
     * 更新代码
     */
    public function  updateDeployProjectCode($project){
        $this->line('');
        $this->info('更新本地代码中....');
        $this->line('');
        $bar = $this->output->createProgressBar(1);
        // 版本库路径
        $projectReposPath = dirname(__FILE__)."/../../../"."storage/repository/".$project['project_name']."/";
        // 如果不存在文件夹，则创建文件夹并下拉代码
        $repository = $project['repository'];
        $this->runLocalShell("if [ ! -d {$projectReposPath} ]; then mkdir -p {$projectReposPath} && git clone {$repository} {$projectReposPath} ; fi  ");
        $bar->finish();
        $this->line('');
        $this->line('');
        //更新代码
        $this->runLocalShell(" cd {$projectReposPath} && git fetch -p && git pull && git reset --hard HEAD");
    }

    /**
     * 选择分支
     */
    public function getDeployProjectBranch($project){
        // 版本库路径
        $projectReposPath = dirname(__FILE__)."/../../../"."storage/repository/".$project['project_name']."/";
        //取出Git对象，并设置变量
        $branches = $this->runLocalShell("cd {$projectReposPath} && git branch -avv --no-abbrev");
        if (preg_match_all('/remotes\/([\S]+)?/m', $branches, $matches)) {
            $branchs = $matches[1];
            $i = 1;
            $branchItems = [];
            foreach ($branchs as $branch) {
                $branchItems[$i] = $branch;
                $i++;
            }
            $branchs_name = $this->choice('请选择分支', $branchItems);
            return $branchs_name;
        } else {
            $this->warn("********无分支********");
            exit;
        }
    }
    /**
     * 选择commit
     */
    public function getDeployProjectCommit($project,$branch){
        $projectReposPath = dirname(__FILE__)."/../../../"."storage/repository/".$project['project_name']."/";
        $count = 10;
        $data = array();
        if(!$data) {
            $cmd = "cd $projectReposPath &&  git checkout {$branch} > /dev/null";
            $cmd = str_replace('\\','/',$cmd);
            $this->runLocalShell($cmd);
            $cmd = "cd $projectReposPath && git  rev-list --header --max-count={$count} '{$branch}' ";
            $rev_list = $this->runLocalShell($cmd);
            foreach(explode("\000", $rev_list) as $rev) {
                $commit = array();
                $rev_lines = explode("\n", str_replace("\r", "", $rev));
                $commit['id'] = $rev_lines[0];
                foreach($rev_lines as $rev_line) {
                    if(substr($rev_line, 0, 4) == "    ") {
                        if(isset($commit['text'])) {
                            $commit['text'] .= "\n".substr($rev_line, 4);
                        } else
                            $commit['text'] = substr($rev_line, 4);
                    } else {
                        $opt = explode(" ", $rev_line);
                        if($opt[0] == "tree") {
                            $commit['tree'] = $opt[1];
                        } else if($opt[0] == "parent") {
                            $commit['parent'][] = $opt[1];
                        } else if($opt[0] == "author") {
                            $commit['author'] = $opt[1];
                            $commit['author_mail'] = $opt[2];
                            $commit['author_time'] = $opt[3];
                        } else if($opt[0] == "committer") {
                            $commit['committer'] = $opt[1];
                            $commit['committer_mail'] = $opt[2];
                        }
                    }
                }
                $data[] = $commit;
            }
        }
        $items = [];
        $i = 1;
        foreach ($data as $item){
            $str =  mb_substr($item['id'],0,7);
            if (isset($item['text'])){
                $str .= " --- ".$item['text'];
            }
            if (!empty($str)) {
                $items[$i] = $str;
                $i++;
            }
            
        }

        $commit  = $this->choice('commit', $items);
        $inputKey = $this->output->ret;
        $inputKey = $inputKey-1;
        return $data[$inputKey]['id'];
    }
    /**
     * 选择部署环境
     *
     * @author DevKang
     * @date 2016年7月19日
     */
    public function  getgetDeployEnvironment($project){

        $serverItem = [];

        $this->stageNames;
        $i = 1;
        $environments = [];
        foreach ($project['serverNames'] as $key => $server){
            $serverItem[$i] = $this->stageNames[$key];
            $serverItemTmp[] = $key;
            $i++;
        }

        $environment  = $this->choice('部署环境', $serverItem,null,null,true);
        $inputKey = $this->output->ret;
        //中文逗号替换成中文逗号
        $inputKey = str_replace('，', ',', $inputKey);
        $inputKeys = explode(",", $inputKey);
        $environments =[];
        foreach ($inputKeys as $inputKeyTmp){
            //换算输入跟数组key
            $inputKeyTmp = $inputKeyTmp-1;
            $environments[] = $serverItemTmp[$inputKeyTmp];
        }
        return $environments;
    }

    public function getDeployProdServer(&$project)
    {
        $servers = $project['serverNames']['prod'];
        $item = ['全部服务器'];
        $i = 1;
        foreach ($servers as $key => $server){
            $item[$i]= $server;
            $i++;
        }
        $prod_server = $this->choice('请选择要部署的生产环境服务器', $item, 0, null, true);
        $inputKey = $this->output->ret;
        $inputKey = str_replace('，', ',', $inputKey);
        $inputKeys = explode(",", $inputKey);
        $serverNames = [];
        foreach ($inputKeys as $inputKey) {
            if ($inputKey == 0) {
                $serverNames = array_merge($serverNames, $servers);
            } else {
                $serverNames[] = $item[$inputKey];
            }
        }

        $project['serverNames']['prod'] = array_unique($serverNames);
    }

    /**
     * 同步GIT代码并打包发送
     *
     * @author DevKang
     * @date 2016年7月19日
     */
    public function syncProjectCode($project,$environment,$commit){
        $this->line('');
        $this->info('上传代码到远程服务器中.....');
        $this->line('');
        $serverNames = $project['serverNames'][$environment];
        
        $bar = $this->output->createProgressBar(1+count($serverNames));

        $projectReposPath = dirname(__FILE__)."/../../../"."storage/repository/".$project['project_name']."/";

        $tarName = uniqid('tmp') . '.tar.gz';

        $tarFilePath = "/tmp/{$tarName}";

        $cmd = " cd $projectReposPath   &&  git archive --format tar.gz --output {$tarFilePath}  {$commit}";
        $bar->advance();
        $this->runLocalShell($cmd);
        $servers = config('servers');

        $deployServers = [];

        foreach ($serverNames as $serverName){
            $deployServers[] = $servers[$serverName];
        }
        //初始化部署变量
        $remoteTarFilePath = "/tmp/{$tarName}";

        $release = date('Y-m-d_H-i-s') . '_' . $commit;

        $releasePath = $this->deploy_path.$this->siteName."/{$project['project_name']}/releases/{$release}";

        $userName = $this->userName;

        foreach ($deployServers as $deployServer){
            $serverHost = $deployServer['host'];
            $cmd = "cd $projectReposPath && rsync -a {$tarFilePath} {$userName}@{$serverHost}:/tmp/";
            $this->runLocalShell($cmd);
            $cmd = "mkdir -p $releasePath && tar -zxf {$remoteTarFilePath} -C {$releasePath}/ && rm {$remoteTarFilePath}";
            $this->runRemoteShell($cmd,$userName,$serverHost);
            $bar->advance();
        }
        $this->runLocalShell("cd $projectReposPath  && rm {$tarFilePath}");
        $bar->finish();
        $this->line('');
        $this->line('');
        return $releasePath;


    }
    /**
     * 为共享文件夹创建软链
     *
     * @author DevKang
     * @date 2016年7月20日
     */
    public function shared_dirs($project,$environment,$releasePath){
        // 共享目录
        $this->line('');
        $this->info('为共享文件夹创建软链.....');
        $this->line('');
        $sharedPath = $this->deploy_path.$this->siteName."/{$project['project_name']}/shared";
        $shared_dirs = $project['shared_dirs'];
        //如果没有共享目录直接跳过
        if(!$shared_dirs){
            return ;
        }
        $serverNames = $project['serverNames'][$environment];
        $deployServers = [];
        $servers = config('servers');
        foreach ($serverNames as $serverName){

            $deployServers[] = $servers[$serverName];
        }
        // 循环处理
        foreach($deployServers as $deployServer){
            $serverHost = $deployServer['host'];
            foreach ($shared_dirs as $dir) {
                // 创建发布目录的共享目录的父目录
                $this->runRemoteShell("mkdir -p `dirname {$releasePath}/$dir`",$this->userName,$serverHost);
                // 移除发布目录的共享目录
                $this->runRemoteShell("if [ -d $(echo {$releasePath}/$dir) ]; then rm -rf {$releasePath}/$dir; fi",$this->userName,$serverHost);
                // 创建公共的共享目录
                $this->runRemoteShell("mkdir -p $sharedPath/$dir",$this->userName,$serverHost);
                // 创建软链到公共的共享目录
                $this->runRemoteShell("ln -nfs $sharedPath/$dir $releasePath/$dir",$this->userName,$serverHost);
            }
        }

    }
    /**
     * 为共享文件创建软链
     *
     * @author DevKang
     * @date 2016年7月20日
     */
    public function shared_files($project,$environment,$releasePath){
        $this->line('');
        $this->info('为共享文件创建软链.....');
        $this->line('');
        // 共享目录
        $sharedPath = $this->deploy_path.$this->siteName."/{$project['project_name']}/shared";
        $shared_files = $project['shared_files'];
        //如果没有共享文件直接跳过
        if(!$shared_files){
            return ;
        }
        $serverNames = $project['serverNames'][$environment];
        $deployServers = [];
        $servers = config('servers');
        foreach ($serverNames as $serverName){

            $deployServers[] = $servers[$serverName];
        }
        // 循环处理
        foreach($deployServers as $deployServer){
            $serverHost = $deployServer['host'];
            foreach ($shared_files as $file) {
                // Remove from source
                $this->runRemoteShell("if [ -f $(echo {$releasePath}/$file) ]; then rm -rf {$releasePath}/$file; fi",$this->userName,$serverHost);

                // Create dir of shared file
                $this->runRemoteShell("mkdir -p $sharedPath/" . dirname($file),$this->userName,$serverHost);

                // Touch shared
                $this->runRemoteShell("touch $sharedPath/$file",$this->userName,$serverHost);

                // Symlink shared dir to release dir
                $this->runRemoteShell("ln -nfs $sharedPath/$file {$releasePath}/$file",$this->userName,$serverHost);
            }
        }

    }
    /**
     * 创建软链
     *
     * @author DevKang
     * @date 2016年7月20日
     */
    public function symlink($project,$environment,$releasePath){
        $this->line('');
        $this->info('创建软链.....');
        $this->line('');
        $deploy_path = $this->deploy_path.$this->siteName."/{$project['project_name']}";
        $serverNames = $project['serverNames'][$environment];
        $deployServers = [];
        $servers = config('servers');
        foreach ($serverNames as $serverName){
            $deployServers[] = $servers[$serverName];
        }
        foreach($deployServers as $deployServer){
            $serverHost = $deployServer['host'];
            $this->runRemoteShell("cd {$deploy_path} && ln -sfn {$releasePath} current",$this->userName,$serverHost); // Atomic override symlink.
            $this->runRemoteShell("cd {$deploy_path} ",$this->userName,$serverHost); // Remove release link.
        }
    }
    /**
     * 清除
     *
     * @author DevKang
     * @date 2016年7月20日
     */
    public function cleanup($project,$environment){
        $deploy_path = $this->deploy_path.$this->siteName."/{$project['project_name']}";
        $releases_path = "{$deploy_path}/releases/";
        $serverNames = $project['serverNames'][$environment];
        $keep_releases = $this->keep_releases;
        //获取部署服务器
        $deployServers = $this->getDeployServers($serverNames);
        // 循环处理
        //todo 如果两台服务器部署路径不一致如何处理？
        foreach($deployServers as $deployServer){
            $userName = $this->userName;
            $serverHost = $deployServer['host'];
            // 查询远程部署服务器上的所有版本
            $lists = $this->runRemoteShell("ls $releases_path", $userName, $serverHost);
            //换行分割数组
            $lists = explode("\n", $lists );
            //删除空数组
            $lists = array_filter($lists);
            //数组从大到小进行排序
            rsort($lists);
            //获取lastNumber值对应数组
            $devLists = array_slice($lists,$keep_releases);
            if ($devLists) {
                foreach ($devLists as $devList) {
                    //删除版本库
                    $this->runRemoteShell("rm -rf {$releases_path}{$devList}", $userName, $serverHost);
                }
            }

        }
    }
    /**
     * 发送邮件
     *
     * @author DevKang
     * @date 2016年7月20日
     */
    public function send_email($project,$environment,$commit){
        $this->line('');
        $this->info('发送邮件中.....');
        $this->line('');
        // 获取项目commit信息
        $sendContent = $this->get_commit_info($project, $commit);
        // 项目中文名称
        $project_cn_name = $project['project_cn_name'];
        //项目环境中文名称
        $environmentName = $this->stageNames[$environment];
        //部署机器
        $serverNames = implode(" , ", $project['serverNames'][$environment]);
        $data = [
            'email'=>"phpteam@name.com",
            'name'=>"团队",
            'project_cn_name'=>$project_cn_name,
            'environmentName'=>$environmentName,
            'sendContent' =>$sendContent,
            'serverNames' => $serverNames
        ];
        Mail::send('email.deploy', $data, function($message) use($data)
        {
            $message->to($data['email'], $data['name'])
                ->subject("[{$data['project_cn_name']}]项目已部署到[{$data['environmentName']}]环境[{$data['serverNames']}]服务器");
        });
    }
    
    
    /**
     * 获取部署commit信息
     *
     * @author DevKang
     * @date 2016年7月20日
     */
    public function get_commit_info($project,$commit){
        $c_commit = $commit;
        $projectReposPath = dirname(__FILE__)."/../../../"."storage/repository/".$project['project_name']."/";
        $count = 10;
        $data = array();
        if(!$data) {
            $cmd = "cd $projectReposPath &&  git checkout develop > /dev/null";
            $cmd = str_replace('\\','/',$cmd);
            $this->runLocalShell($cmd);
            $cmd = "cd $projectReposPath && git  rev-list --header --max-count={$count} '{$c_commit}' ";
            $rev_list = $this->runLocalShell($cmd);
            foreach(explode("\000", $rev_list) as $rev) {
                $commit = array();
                $rev_lines = explode("\n", str_replace("\r", "", $rev));
                $commit['id'] = $rev_lines[0];
                foreach($rev_lines as $rev_line) {
                    if(substr($rev_line, 0, 4) == "    ") {
                        if(isset($commit['text'])) {
                            $commit['text'] .= "\n".substr($rev_line, 4);
                        } else
                            $commit['text'] = substr($rev_line, 4);
                    } else {
                        $opt = explode(" ", $rev_line);
                        if($opt[0] == "tree") {
                            $commit['tree'] = $opt[1];
                        } else if($opt[0] == "parent") {
                            $commit['parent'][] = $opt[1];
                        } else if($opt[0] == "author") {
                            $commit['author'] = $opt[1];
                            $commit['author_mail'] = $opt[2];
                            $commit['author_time'] = $opt[3];
                        } else if($opt[0] == "committer") {
                            $commit['committer'] = $opt[1];
                            $commit['committer_mail'] = $opt[2];
                        }
                    }
                }
                $data[] = $commit;
            }
        }
        $items = [];
        $i = 1;
        $sendContent = '';
    
        foreach ($data as $item){
            $str =  mb_substr($item['id'],0,7);
            if ($c_commit == $str){
                $parent_commits = $item['parent'];
                $date = date("Y-m-d H:i:s",$item['author_time']);
                $sendContent .="
                发布版本：{$c_commit}<br/>
                作者：{$item['author']}<br/>
                描述：{$item['text']}<br/>
                时间：{$date}<br/><br/>
                ＝＝＝＝＝＝＝＝＝＝＝以下为所有父节点信息＝＝＝＝＝＝＝＝＝＝＝＝＝
                <br/>
                <br/>
                ";
            }
        }
        if ($parent_commits){
            foreach ($parent_commits as $parent_commit) {
                foreach ($data as $item){
                    if ($item['id']==$parent_commit){
                        $date = date("Y-m-d H:i:s",$item['author_time']);
                        $id = mb_substr($item['id'],0,7);
                        $sendContent .= "
                版本：{$id}<br/>
                作者：{$item['author']}<br/>
                描述：{$item['text']}<br/>
                时间：{$date}<br/><br/>
                        ";
                    }
                }
            }
        }
        return $sendContent;
    }
    
    /**
     * 获取所有的项目
     *
     * @return array
     */
    private function getDeployProject()
    {

        $projects = config('projects');
        $item =[];
        $i = 1;
        foreach ($projects as $key => $project){
            $item[$i]= $project['project_cn_name'];
            $i++;
        }
        $project_name = $this->choice('请选择部署项目', $item);
        $inputKey = $this->output->ret;
        $inputKey = $inputKey-1;
        return $projects[$inputKey];
    }

    /**
     * 获取部署服务器
     *
     * @return array
     */
    private function getDeployServers($serverNames)
    {
        $deployServers = [];
        $servers = config('servers');

        foreach ($serverNames as $serverName){
            $deployServers[] = $servers[$serverName];
        }
        return $deployServers;

    }
    /**
     * 获取发布版本的历史版本路径
     *
     * @return string
     */
    private function getHistoryReleasePath($project,$environment,$lastNumber)
    {

        $deploy_path = $this->deploy_path.$this->siteName."/{$project['project_name']}";
        $releases_path = "{$deploy_path}/releases/";
        $serverNames = $project['serverNames'][$environment];
        $deployServers = $this->getDeployServers($serverNames);
        // 循环处理
        //todo 如果两台服务器部署路径不一致如何处理？
        foreach($deployServers as $deployServer){
            $userName = $this->userName;
            $serverHost = $deployServer['host'];
            // 查询远程部署服务器上的所有版本

            $lists = $this->runRemoteShell("ls $releases_path", $userName, $serverHost);
//            rsort($lists);
        }
        //换行分割数组
        $lists = explode("\n", $lists );
        //删除空数组
        $lists = array_filter($lists);
        //按照键值进行降序排序
        rsort($lists);
        //获取lastNumber对应数组
        $list = array_slice($lists,$lastNumber-1,1);
        //返回版本路径
        return $releases_path."{$list[0]}";
    }


    /**
     * 删除发布版本
     */
    private function delHistoryReleasePath($project,$environment,$lastNumber)
    {

        $deploy_path = $this->deploy_path.$this->siteName."/{$project['project_name']}";
        $releases_path = "{$deploy_path}/releases/";
        $serverNames = $project['serverNames'][$environment];
        $deployServers = $this->getDeployServers($serverNames);
        // 循环处理
        //todo 如果两台服务器部署路径不一致如何处理？
        foreach($deployServers as $deployServer){
            $userName = $this->userName;
            $serverHost = $deployServer['host'];
            // 查询远程部署服务器上的所有版本

            $lists = $this->runRemoteShell("ls $releases_path", $userName, $serverHost);

            //换行分割数组
            $lists = explode("\n", $lists );
            //删除空数组
            $lists = array_filter($lists);
            //数组从大到小进行排序
            rsort($lists);
            //获取lastNumber值对应数组
            $list = array_slice($lists,$lastNumber-1, 1);
            //删除版本库
            $lists = $this->runRemoteShell("rm -rf {$releases_path}{$list[0]}", $userName, $serverHost);
        }
       
    }

    /**
     * A wrapper around symfony's formatter helper to output a block.
     *
     * @param string|array $messages Messages to output
     * @param string       $type     The type of message to output
     */
    protected function block($messages, $type = 'error')
    {
        $output = [];

        if (!is_array($messages)) {
            $messages = (array) $messages;
        }

        $output[] = '';

        foreach ($messages as $message) {
            $output[] = trim($message);
        }

        $output[] = '';

        $formatter = new FormatterHelper();
        $this->line($formatter->formatBlock($output, $type));
    }

    /**
     * Outputs a header block.
     *
     * @param string $header The text to output
     */
    protected function header($header)
    {
        $this->block($header, 'question');
    }
    /**
     * 远程服务器执行SHELL
     *
     * @author DevKang
     * @date 2016年7月20日
     */
    public function runRemoteShell($cmd,$userName,$serverHost)
    {
        $cmd="ssh -o CheckHostIP=no \
        -o IdentitiesOnly=yes \
        -o StrictHostKeyChecking=no \
        -o PasswordAuthentication=no \
        {$userName}@{$serverHost} '
        {$cmd}'";
        $process = new Process($cmd);
        $process->run();
        return $process->getOutput();
    }
    /**
     * 执行本地shell
     *
     * @author DevKang
     * @date 2016年7月20日
     */
    public function runLocalShell($cmd)
    {
        $process = new Process($cmd);
        $process->run();
        return $process->getOutput();
    }
}
