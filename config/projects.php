<?php
/**
 * Created by PhpStorm.
 * User: kangyankun
 * Date: 2016/7/14
 * Time: 14:57
 */
return [
    [
        'project_cn_name' => 'foo',
        // 项目英文名称，也作为项目部署目录使用
        'project_name' => 'foo',
        // 项目git仓库地址
        'repository' => 'git@github/example/foo.git',
        'serverNames' => [
            'test' => [
                // 对应服务器配置信息的key
                'test'
            ],
            'stage' => [
                // 对应服务器配置信息的key
                'stage'
            ],
            'prod' => [
                // 对应服务器配置信息的key
                'prod',
                'e602'
            ]
        ],
        // 共享文件夹，每次部署都会自动软连到固定的文件夹
        'shared_dirs' => [
            'src/runtime'
        ],
        // 共享文件，每次部署都会自动软连到固定的文件夹
        'shared_files' => [
            'src/sbin/.env',
        ],
    ]
];