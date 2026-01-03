<?php

namespace App\Models\ApiRepositories;

class OpenChatApiArgs
{
    public int $page;
    public int $limit;
    public int $category;
    public string $order;
    public string $sort;
    public string $list = '';
    public string $sub_category = '';
    public string $keyword = '';
    public string $tag = '';
    public int $badge = 0;
}
