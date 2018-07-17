<?php
namespace App\Business\Data;

use App\Exceptions\KrException;
use JingData\Models\Crm\Tag as TagModel;
use JingData\Models\Crm\TagCompanySet;
use JingData\Models\Crm\TagRelation;
use JingData\Models\Crm\ExttagRelation;
use JingData\Models\Crm\Industry;
use JingData\Models\Crm\TagExampleCompany as TagExampleCompanyModel;
use JingData\Models\Crm\TagManualRelation as Map;
use JingData\Models\Crm\OperateLog;
use JingData\Models\Crm\IndustryMap as IndustryMapModel;

class Tag
{
    public $tree = [];
    public $label = [];
    public $dictTag = [];
    public $industry = [];
    public $tags = [];
    public $relations = [];
    public function getQuery()
    {
        return TagModel::query()->with(
            [
                'example_company',
                'example_company.company',
                'user',
                'related_tags'
            ]
        );
    }

    public function getSearchHandle()
    {
        return [
            'keywords' => function ($q, $value) {
                $value = trim($value);
                $q->where('name', 'like', "%$value%")->orderBy('id', 'desc');
            },
            'industry' => function ($q, $value) {
                if (!$value) {
                    return;
                }
                return $q->where('industry', $value)->orderBy('id', 'desc');
            },
            'level' => function ($q, $value) {
                if (!$value) {
                    return;
                }
                return $q->where('level', $value)->orderBy('id', 'desc');
            },
            'quality' => function ($q, $value) {
                if ($value == 1) {
                    return $q->where('quality', $value)->orderBy('id', 'desc');
                }
            },
            'intree' => function ($q, $value) {
                if ($value == 1) {
                    return $q->has('parent_tags')->orHas('child_tags');
                } elseif ($value == 2) {
                    return $q->doesntHave('parent_tags')->doesntHave('child_tags');
                }
            },
            'count' => function ($q, $value) {
                if ($value) {
                    $sql = explode(', ', $q->withCount(
                        ['company_tags' => function ($q) {
                            $q->whereHas(
                                'company',
                                function ($q) {
                                    $q->where('status', 2);
                                }
                            );
                        }]
                    )->toSql())[1];
                    $newSql = str_replace('?', '2', rtrim(explode('as', $sql)[0]));

                    $q->whereRaw($newSql . ' >= ' . $value);
                }
            },
            'pageType' => function ($q, $value) {
                switch ($value) {
                    case 'all':
                        return $q;
                    case 'handled':
                        return $q->where('is_deleted', 0)
                            ->whereHas(
                                'related_tags',
                                function ($query) {
                                    $query->whereRaw('id is not null');
                                },
                                '<'
                            )->orderBy('id', 'desc');
                    case 'related':
                        return $q->whereHas(
                            'related_tags',
                            function ($query) {
                                return $query->where('id', '>', 0);
                            }
                        )->orderBy('id', 'desc');
                    case 'deleted':
                        return $q->where('is_deleted', 1)->orderBy('id', 'desc');
                    default:
                        // code...
                        break;
                }
            }
        ];
    }

    public function params($request, $handle = [])
    {
        $searchKey = array_keys($handle);
        $searchKey = array_filter(
            $searchKey,
            function ($item) {
                return stripos($item, '__') === false;
            }
        );
        array_push($searchKey, 'pageSize');
        return $request->only($searchKey);
    }

    public function search($query, $params, $handles)
    {
        foreach ($handles as $key => $handle) {
            if (isset($params[$key]) && is_callable($handle)) {
                $handle($query, $params[$key]);
            }
        }
        return $query;
    }

    public function show($id)
    {
        $data = [];
        $model = $this->getQuery()->with(
            [
                'parent_tags',
                'child_tags',
                'related_tags',
                'relation_tags',
                'set',
                'user',
                'example_company.company'
            ]
        )->find($id);
        $data['logs'] = OperateLog::where('rid', $id)
            ->where('type', 'basic.tag.operation')
            ->orderBy('update_time', 'desc')
            ->with('operator')->get();
        $data['model'] = $model;

        $tagTree = [];
        $parent = $model->parent_tags->first();
        while ($parent) {
            $tagTree[] = $parent['parent_tag'];
            $parent = $parent->parent;
        }

        $mapTree = [];
        $map = IndustryMapModel::whereRaw("find_in_set('{$model->name}',`value`)")
            ->first();
        if ($map) {
            $mapTree[] = $map['name'];
            if ($map->parent) {
                $mapTree[] = $map->parent['name'];
            }
        }

        if (!empty($tagTree)) {
            array_unshift($tagTree, $model->name);
        }
        if (!empty($mapTree)) {
            array_unshift($mapTree, $model->name);
        }
        $model['tagTree'] = array_reverse($tagTree);
        $model['mapTree'] = array_reverse($mapTree);

        return $data;
    }

    public function showByTag($tag)
    {
        $data = [];
        $model = $this->getQuery()->with(
            [
                'parent_tags',
                'child_tags',
                'related_tags',
                'relation_tags',
                'set',
                'user',
                'example_company.company'
            ]
        )->where('name', $tag)->first();
        $data['model'] = $model;
        return $data;
    }

    public function store($data)
    {
        $companyExample = $data['exampleCompany'];
        $relationTag = $data['parentTags'];
        $remarkNames = $data['remarkNames'];
        unset($data['exampleCompany']);
        unset($data['parentTags']);
        unset($data['remarkNames']);
        $user = app('user');
        $data['uid'] = $user->id;
        $model = TagModel::with(
            [
                'relation_tags'
            ]
        )->where('name', $data['name'])->first();
        if ($model) {
            throw new KrException('标签已存在！', 2100);
        } else {
            $model = TagModel::create($data);
        }

        foreach ($companyExample as $cid) {
            try {
                TagExampleCompanyModel::create(
                    ['cid'=>$cid, 'tag'=> $model->name]
                );
            } catch (KrException $e) {
                continue;
            }
        }
        foreach ($relationTag as $pt) {
            try {
                TagRelation::create(
                    [
                        'tag'=>$model->name,
                        'parent_tag' => $pt['tag'],
                    ]
                );
            } catch (KrException $e) {
                continue;
            }
        }
        foreach ($remarkNames as $rk) {
            try {
                ExttagRelation::create(
                    [
                        'tag'=>$model->name,
                        'exttag' => $rk,
                    ]
                );
                ExttagRelation::where('tag', $rk)
                    ->update(['tag' => $data['name']]);
            } catch (KrException $e) {
                continue;
            }
        }
        $mapRes = Map::where('data_type', 0)->get();
        $mapTags = [];
        $mapRes->each(
            function ($item) use (&$mapTags) {
                array_push($mapTags, $item->tag);
                $values = json_decode($item->value, true);
                foreach ($values as $key => $value) {
                    $mapTags = array_merge($mapTags, @$value['tags']?:[]);
                }
                $mapTags = array_unique($mapTags);
            }
        );
        \Redis::set('map-tags', json_encode($mapTags, JSON_UNESCAPED_UNICODE));
        \Artisan::call('pintu:tag-tree');
        \Artisan::call('pintu:tag-label', ['type' => 'industry']);
        app('operation.log')->set('tag.id', $model->id);
        app('operation.log')->set('tag.name', $model->name);
        return $model;
    }

    public function update($data, $id)
    {
        $companyExample = $data['exampleCompany'];
        $relationTag = $data['parentTags'];
        $remarkNames = $data['remarkNames'];
        unset($data['exampleCompany']);
        unset($data['parentTags']);
        unset($data['remarkNames']);
        $tagTree = json_decode(\Redis::get('tree-tag'));
        // $extTag = $request->get('ext_tag', []);
        $data['uid'] = app('user')->id;
        $model = TagModel::with('child_tags', 'parent_tags')->find($id);

        // 不允许改成一个已经存在的标签名
        $checkModel = TagModel::where('name', $data['name'])->where('id', '!=', $id)->first();
        if ($checkModel) {
            throw new KrException('标签名称已存在', 2100);
        }

        if ($data['level'] != $model->level) {
            if (in_array($model->name, $tagTree)) {
                throw new KrException('该标签在标签树上，不能修改标签类别！', 2000);
            }
        }
        if ($data['name'] != $model->name) {
            $this->isInIndustryMap($model->name);
            ExttagRelation::firstOrCreate(
                [
                    'exttag'=>$model->name,
                    'tag'=>$data['name'],
                ]
            );
            $industry = Industry::where('name', $model->name)->update(['name' => $data['name']]);
        } else {
            $industries = $this->setIndustry();
            $tags = $this->setTags();
            $relationsAll = $this->relations($industries, $tags);
            $subTags = [];
            $this->getSubTags($relationsAll, $model->name, $subTags);
            array_push($subTags, $model->name);
            $parentTags = [];
            foreach ($relationTag as $rt) {
                $this->getParentTags($relationsAll, $rt['tag'], $parentTags);
            }
            $parentTags += array_column($relationTag, 'tag');
            if (count(array_intersect($subTags, $parentTags))
                || count(array_intersect($parentTags, $subTags))
            ) {
                throw new KrException('父子关系冲突', 2000);
            }
        }

        TagExampleCompanyModel::where(
            [
                'tag' => $model->name,
            ]
        )->forceDelete();
        foreach ($companyExample as $cid) {
            TagExampleCompanyModel::firstOrCreate(
                [
                    'cid' => $cid, 'tag' => $data['name']
                ]
            );
        }
        $model->parent_tags()->forceDelete();
        foreach ($relationTag as $pt) {
            TagRelation::firstOrCreate(
                [
                    'tag' => $data['name'],
                    'parent_tag' => $pt['tag']
                ]
            );
        }
        $model->child_tags()->update(
            [
                'parent_tag' => $data['name']
            ]
        );
        $model->relation_tags()->forceDelete();
        foreach ($remarkNames as $ext) {
            $extModel = TagModel::where('name', $ext)->first();
            if (!$extModel) {
                TagModel::create(
                    [
                        'name' => $ext,
                        'level' => 'C',
                        'industry' => $data['industry']
                    ]
                );
            }
            ExttagRelation::firstOrCreate(
                [
                    'tag'=>$data['name'],
                    'exttag'=>$ext,
                ]
            );
            ExttagRelation::where('tag', $ext)
                ->update(['tag' => $data['name']]);
        }
        $model->set()->update(['tag'=>$data['name']]);
        $oldData = [
            'name' => $model->name,
            'brief' => $model->brief,
            'industry' => $model->industry,
            'level' => $model->level,
            'uid' => $model->uid,
            'expose' => $model->expose,
            'quality' => $model->quality,
        ];
        $model->update($data);
        $oldModel = TagModel::where('name', $oldData['name'])->first();
        if (!$oldModel) {
            TagModel::create($oldData);
        }
        \Artisan::call('pintu:tag-tree');
        \Artisan::call('pintu:tag-label', ['type' => 'industry']);
        app('operation.log')->set('tag.id', $model->id);
        app('operation.log')->set('tag.name', $model->name);
        return $model;
    }

    private function isInIndustryMap($tag)
    {
        $mapRes = Map::where('data_type', 0)->get();
        $mapTags = [];
        $mapRes->each(
            function ($item) use (&$mapTags) {
                array_push($mapTags, $item->tag);
                $values = json_decode($item->value, true);
                foreach ($values as $key => $value) {
                    $mapTags = array_merge($mapTags, @$value['tags']?:[]);
                }
                $mapTags = array_unique($mapTags);
            }
        );
        \Redis::set('map-tags', json_encode($mapTags, JSON_UNESCAPED_UNICODE));
        if (in_array($tag, $mapTags)) {
            throw new KrException('该标签在行业图谱中，禁止修改名称！', 2000);
        }
        // 下面是拼图的新的行业图谱判断
        $mapTags = [];
        $tags = IndustryMapModel::where('pid', 0)->pluck('name')->toArray();
        $values = IndustryMapModel::where('pid', '!=', 0)->pluck('value')->toArray();
        array_map(
            function ($item) use ($mapTags) {
                if ($item) {
                    $tmp = explode(',', $item);
                    $mapTags = array_merge($mapTags, $tmp);
                }
            },
            $values
        );
        $mapTags = array_merge($mapTags, $tags);
        if (in_array($tag, $mapTags)) {
            throw new KrException('该标签在行业图谱中，禁止修改名称！', 2000);
        }
    }

    public function relate($relatedTag, $id)
    {
        $model = TagModel::with('parent_tags', 'child_tags')->find($id);
        $msg = '';
        $relateName = $relatedTag;
        $relation = ExttagRelation::pluck('tag', 'exttag')->toArray();
        $relateModel = TagModel::where('name', $relatedTag)->with('related_tags')->first();
        if ($relateModel->is_deleted == 1) {
            throw new KrException('目标标签已被删除，请检查后再试。。', 2100);
        }
        if ($relateModel->related_tags) {
            throw new KrException('目标标签已被归一，请检查后再试。。', 2100);
        }
        if ($model->parent_tags->count() || $model->child_tags->count()) {
            throw new KrException('当前标签有父标签或者子标签。。', 2100);
        }
        $result = ['result'=>true,'target'=>$relateName];
        $this->checkTag($relation, $relateName, $model->name, $result);
        if (!$result['result']) {
            throw new KrException('关联关系冲突，请检查后再试。。', 2100);
        } elseif ($result['target'] != $relateName) {
            $relateName = $result['target'];
            $msg = '该标签已关联至目标标签的关联标签'.$relateName;
        }
        // 删除标签的父标签
        $model->parent_tags()->forceDelete();
        // 所有该标签归一到的标签
        // $model->exttag_relation()->forceDelete();
        $related = $model->load('relation_tags')->relation_tags;
        $model->relation_tags()->forceDelete();
        foreach ($related as $re) {
            ExttagRelation::create(['tag'=>$relateName,'exttag'=>$re['exttag']]);
        }
        $res = ExttagRelation::create(['tag'=>$relateName,'exttag'=>$model->name]);
        app('operation.log')->set('tag.id', $model->id);
        app('operation.log')->set('tag.name', $model->name);
        app('operation.log')->set('tag.origin_name', $relatedTag);
        return ['result' => $model, 'msg' => $msg];
    }

    public function discard($relatedTag, $is_relate, $id)
    {
        if ($is_relate == 1 && $relatedTag == '') {
            throw new KrException('必须选择标签', 2100);
        }

        $model = TagModel::with('child_tags', 'set', 'parent_tags', 'relation_tags')->find($id);
        if ($model->child_tags->count()) {
            throw new KrException('该标签存在子标签，不能删除', 2100);
        }

        if ($model->parent_tags->count()) {
            throw new KrException('该标签有父标签，不能删除', 2100);
        }

        if ($model->set && $model->set->status == 0) {
            throw new KrException('该标签存在项目集，不能删除！', 2000);
        }

        if ($model->relation_tags->count()) {
            throw new KrException('该标签存在归一标签，不能删除！', 2000);
        }

        foreach ($model->child_tags as $child_tag) {
            $set = TagCompanySet::where('tag', $child_tag->tag)
                ->where('status', 0)->first();
            if ($set) {
                throw new KrException('该标签的子标签'.$child_tag->tag.'存在项目集，不能删除！', 2000);
            }
        }

        if (in_array($model->level, ['A', 'B'])) {
            $this->isInIndustryMap($model->name);
            $model->is_deleted = 1;
        } else {
            $model->is_deleted = 1;
        }
        $model->save();
        // $model->relation_tags()->forceDelete();
        $result = [
            'result' => $model,
            'msg' => ''
        ];
        if ($is_relate == 1) {
            $result = $this->relate($relatedTag, $id);
        }
        app('operation.log')->set('tag.id', $model->id);
        app('operation.log')->set('tag.name', $model->name);
        return $result;
    }

    public function recover($id)
    {
        $comment = '';
        $model = TagModel::with('related_tags')->find($id);
        if ($model->related_tags) {
            $comment = "把标签【{$model->name}】和标签【{$model->related_tags->tag}】解除归一关系";
            $model->related_tags->forceDelete();
        }
        if ($model->is_deleted == 1) {
            $comment = "把标签【{$model->name}】从删除中恢复";
            $model->is_deleted = 0;
        }
        $model->save();
        app('operation.log')->set('tag.id', $model->id);
        app('operation.log')->set('tag.comment', $comment);
        return $model;
    }

    private function checkTag($relations, $tag, $oldTag, &$result)
    {
        if ($tag === $oldTag) {
            $result['result'] = false;
            $result['target'] = '';
        }
        foreach ($relations as $ext => $vtag) {
            if ($tag == $ext) {
                if ($vtag === $oldTag) {
                    $result['result'] = false;
                    $result['target'] = '';
                } else {
                    $result['result'] = true;
                    $result['target'] = $vtag;
                }
                if (isset($relations[$vtag])) {
                    $this->checkTag($relations, $vtag, $oldTag, $result);
                }
            }
        }
    }

    public function tree()
    {
        $industries = $this->setIndustry();
        $tags = $this->setTags();
        $relationsAll = $this->relations($industries, $tags);
        $tagValue = $this->tagValue($this->relations, $tags);
        foreach ($relationsAll as $key => &$relation) {
            $relation['value'] = $tagValue[$relation['tag']];
        }
        $tree = $this->generateTree($relationsAll);
        return ['tree' => $tree, 'tags' => collect($tags)->pluck('id', 'tag')->toArray()];
    }

    public function subList($tagList)
    {
        if (is_array($tagList)) {
            $tagStr = implode(',', $tagList);
        } else {
            $tagStr = $tagList;
            $tagList = explode(',', $tagList);
        }
        $tags = TagModel::whereIn('name', $tagList)->with('child_tags')->get();
        $result = [];
        $tags->each(
            function ($item) use (&$result) {
                $childTags = $item->child_tags->pluck('tag')->toArray();
                $result[$item->name] = $childTags;
            }
        );
        return $result;
    }

    /**
     *获取标签树
     *
     * @param string $type  类型，type=all时返回所有标签，包括游离标签，type=industry时返回行业标签
     * @return array
     */
    public function dictTagLabel($type = 'all')
    {
        $industries = $this->setIndustry();
        $tags = $this->setTags();
        $tags = array_filter(
            $tags,
            function ($item) {
                return $item['related_tags'] === null;
            }
        );
        $tags = array_values($tags);
        $dictTagName = array_column($tags, 'name');
        $relationsQuery = TagRelation::select(\DB::raw('tag, parent_tag as ptag'));

        $relations = $relationsQuery->get()
            ->each(
                function ($item) use ($tags) {
                    $item->value = "";
                    $item->subtags = [];
                    $item->brief = @$tags[array_search($item->tag, array_column($tags, 'name'))]['brief'];
                }
            )->toArray();
        // 去除技术标签
        if ($type != 'all') {
            foreach ($relations as $key => $relation) {
                if (!in_array($relation['tag'], $tags)) {
                    unset($relations[$key]);
                }
            }
        }
        $relationsAll = $this->relations($industries, $tags);
        $tagValue = $this->dealTagValue($relationsAll, $tags);
        $exttags = ExttagRelation::select('tag', 'exttag')->get()->groupBy('tag');
        $label = [];
        foreach ($tagValue as $tag => $value) {
            $search = $value['tag'];
            if ($exttags->has($value['tag'])) {
                $search .= '('.$exttags[$value['tag']]->implode('exttag', ',') . ')';
            }
            array_push(
                $label,
                [
                    'label'=>$value['tag'],
                    'value'=>$value['value'],
                    'search' => $search,
                    'path' => $value['ptag'] . '  \\  ' . $value['tag'],
                    'id' => $value['id']
                ]
            );
        }
        if ($type == 'all') {
            $extTag = TagModel::select('dict_exttag.*')
                ->whereHas(
                    'related_tags',
                    function ($query) {
                        return $query->where('id', '>', 0);
                    },
                    '<'
                )->where('is_deleted', 0)->get();
            $labelKey = array_column($label, 'label');
            foreach ($extTag as $tag) {
                if (!in_array($tag['name'], $labelKey)) {
                    $search = $tag['name'];
                    if ($exttags->has($search)) {
                        $search .= '('.$exttags[$search]->implode('exttag', ',') . ')';
                    }
                    array_push(
                        $label,
                        [
                            'label' => $tag['name'],
                            'value' => $tag['name'],
                            'search' => $search,
                            'id' => $tag['id'],
                            'path' => $tag['name'],
                        ]
                    );
                }
            }
        }

        return ['label' => $label, 'dictTags' =>$tags];
    }

    protected function relations($industries, &$tags)
    {
        array_walk(
            $tags,
            function (&$item) {
                $item['tag'] = $item['name'];
                $item['ptag'] = "";
                $item['value'] = "";
                $item['subtags'] = [];
                unset($item['name']);
            }
        );
        $topTags = array_filter(
            $tags,
            function ($item) use ($industries) {
                return in_array($item['tag'], array_column($industries, 'name'));
            }
        );

        $relations =  TagRelation::select(\DB::raw('tag, parent_tag as ptag, id'))->get()->toArray();
        $ids = array_column($tags, 'id');
        $tagsArray = array_column($tags, 'tag');
        array_walk(
            $relations,
            function (&$item) use ($tags, $ids, $tagsArray) {
                $item['value'] = "";
                $item['subtags'] = [];
                if (($index = array_search($item['tag'], $tagsArray)) !== false) {
                    $item['brief'] = @$tags[$index]['brief'];
                } else {
                    $item['breif'] = '';
                }
            }
        );
        $relations = array_filter(
            $relations,
            function (&$item) use ($tags, $ids, $tagsArray) {
                return in_array($item['tag'], $tagsArray);
            }
        );
        $relationsAll = array_merge($relations, $topTags);
        $this->relations = $relations;
        return $relationsAll;
    }

    protected function setTags()
    {
        $tags = TagModel::whereIn('level', ['A', 'B', 'D'])
            ->with('related_tags')
            // ->with('relation_tags')
            ->select('name', 'id', 'brief')
            ->where('is_deleted', 0)->get()->toArray();
        $this->tags = $tags;
        return $this->tags;
    }

    public function setList($wd)
    {
        $wd = addcslashes($wd, '\'\\"_%'); // 进行过滤
        $list = TagCompanySet::where('tag', 'like', "%$wd%")
            ->where('status', 0)
            ->orderByRaw("tag = '$wd' desc")
            ->limit(20)
            ->get()
            ->each(
                function ($item) {
                    $item->value = $item->id;
                    $item->label = $item->tag;
                }
            );
        return $list;
    }

    public function setStore($tag)
    {
        $set = TagCompanySet::where('tag', $tag)->first();
        if ($set) {
            $set->status = 0;
            $set->save();
            $comment = '恢复项目集';
        } else {
            $set = TagCompanySet::create(
                [
                    'tag' => $tag
                ]
            );
            $comment = '新建项目集';
        }
        app('operation.log')->set('set.id', $set->id);
        app('operation.log')->set('set.comment', $comment);
        return $set;
    }

    public function setIndustry()
    {
        $industries = Industry::select('name', 'id')->get()->toArray();
        $this->industry = $industries;
        return $this->industry;
    }

    public function invGroupCount()
    {
        $industries = $this->setIndustry();
        $tags = $this->setTags();
        $relationsAll = $this->relations($industries, $tags);
        $invGroupCount = TagModel::join('tag_company_set', 'dict_exttag.name', '=', 'tag_company_set.tag')
            ->join('tag_company_set_item', 'tag_company_set_item.tag_company_set_id', '=', 'tag_company_set.id')
            ->join('investment', 'investment.cid', '=', 'tag_company_set_item.cid')
            ->where('investment.status', 1)
            ->where('tag_company_set.status', 0)
            ->where('tag_company_set_item.status', 5)
            ->where('investment.finance_date', '>', '2015-12-31')
            ->select(\DB::raw('count(distinct tag_company_set_item.cid) as cnt, dict_exttag.name'))
            ->groupBy('tag_company_set.id')
            ->pluck('cnt', 'dict_exttag.name')
            ->toArray();
        $tagValue = $this->tagValue($this->relations, $tags);
        usort(
            $tagValue,
            function ($a, $b) {
                return count(explode(',', $a)) >= count(explode(',', $b)) ? -1 : 1;
            }
        );
        foreach ($tagValue as $tv) {
            $pathValues = explode(',', $tv);
            $tk = $pathValues[0];
            $pathValues = array_slice($pathValues, 0, 2);
            if (isset($invGroupCount[$tk])) {
                foreach ($pathValues as $pv) {
                    if (!isset($invGroupCount[$pv])) {
                        $invGroupCount[$pv] = 0;
                    }
                    if ($pv !== $tk) {
                        $invGroupCount[$pv] += $invGroupCount[$tk];
                    }
                }
            } else {
                $invGroupCount[$tk] = 0;
            }
        }
        return $invGroupCount;
    }

    public function treeStore($parent_tag, $tag, $num)
    {
        if ($parent_tag == $tag) {
            throw new KrException('父子标签冲突', 2000);
        }

        $newParentTag = [
            $parent_tag
        ];

        $modelList = TagModel::with(
            [
                'related_tags', 'parent_tags'
            ]
        )->where('name', $tag)->get();
        if ($modelList->count() === 0) {
            throw new KrException('该标签在标签池中不存在！', 2000);
        }
        $modelA = $modelList->whereIn('level', ['A'])->first();

        if (!$modelA && !$parent_tag) {
            throw new KrException('一级标签必须为A类标签！', 2000);
        }

        $model = $modelList->whereIn('level', ['A', 'B', 'C', 'D'])->first();
        if (!$model) {
            throw new KrException('该标签不存在', 2000);
        }
        if ($model->level === 'C') {
            $model->level = 'B';
            $model->save();
        }
        if ($model->is_deleted == 1 || $model->related_tags) {
            throw new KrException('该标签被归一或者被删除', 2000);
        }

        if ($num > 1 && $model->level === 'A') {
            throw new KrException('A类标签只能作为一级标签', 2000);
        }
        if ($model) {
            $industries = $this->setIndustry();
            $tags = $this->setTags();
            $relationsAll = $this->relations($industries, $tags);
            $subTags = [];
            $this->getSubTags($relationsAll, $tag, $subTags);
            array_push($subTags, $tag);
            $parentTags = [];
            $this->getParentTags($relationsAll, $newParentTag, $parentTags);
            array_push($parentTags, $parent_tag);
            if (count(array_intersect($subTags, $parentTags))
                || count(array_intersect($parentTags, $subTags))
            ) {
                throw new KrException('父子关系冲突', 2000);
            }

            if ($model->parent_tags->where('parent_tag', $parent_tag)->first()) {
                throw new KrException('父子关系已经存在', 2000);
            }
        } else {
            throw new KrException('只能操作A,B级标签', 2000);
        }

        if ($parent_tag) {
            TagRelation::firstOrCreate(
                [
                    'tag' => $tag,
                    'parent_tag' => $parent_tag
                ]
            );
        }

        if ($modelA) {
            Industry::firstOrCreate(['name'=>$tag]);
        }
        \Artisan::call('pintu:tag-tree');
        app('operation.log')->set('tree.parent_tag', $parent_tag);
        app('operation.log')->set('tree.tag', $tag);
    }

    public function treeTagDelete($tag, $parent_tag)
    {
        $model = TagModel::where('name', $tag)
            ->with('child_tags', 'set')->first();
        TagRelation::where(
            [
                'parent_tag' => $parent_tag,
                'tag' => $tag
            ]
        )->forceDelete();
        if (!$parent_tag) {
            throw new KrException('一级标签不能删除！', 2100);
        }
        \Artisan::call('pintu:tag-tree');
        app('operation.log')->set('tree.parent_tag', $parent_tag);
        app('operation.log')->set('tree.tag', $tag);
        return true;
    }

    public function treeLogs()
    {
        $logs = OperateLog::with(['operator'])->where('type', 'basic.tree.operation')
            ->orderBy('update_time', 'desc')
            ->get();
        return $logs;
    }

    public function getSubTags($relations, $tag, &$subTags)
    {
        foreach ($relations as $relation) {
            if ($relation['ptag'] === $tag) {
                array_push($subTags, $relation['tag']);
                $this->getSubTags($relations, $relation['tag'], $subTags);
            }
        }
    }

    public function getParentTags($relations, $ptag, &$parentTags)
    {
        foreach ($relations as $relation) {
            if ($relation['tag'] === $ptag) {
                if ($relation['ptag']) {
                    array_push($parentTags, $relation['ptag']);
                    $this->getParentTags($relations, $relation['ptag'], $parentTags);
                }
            }
        }
    }

    public function dealTagValue($relations, $dictTag)
    {
        $tagPtag = [];
        $ptag = [];
        foreach ($relations as $relation) {
            $list = [];
            if (! isset($tagPtag[$relation['tag']])) {
                $tagPtag[$relation['tag']] = [];
            }
            if (! isset($ptag[$relation['tag']])) {
                $ptag[$relation['tag']] = [];
            }
            $list['ptag'] = $relation['ptag'];
            $list['id'] = $relation['id'];
            $list['tag'] = $relation['tag'];
            array_push($ptag[$relation['tag']], $list);
            array_push($tagPtag[$relation['tag']], $relation['ptag']);
        }
        $tagValue = [];  //tag-value字典
        foreach ($tagPtag as $tp => $tv) {
            foreach ($tv as $k => $v) {
                $tagList = [];
                $this->formateTag($tp, $tagList, $tagPtag);
                array_reverse($tagList);
                $tagValue[$tp] = implode(',', $tagList);
            }
        }
        foreach ($dictTag as $ht) {
            if (!array_key_exists($ht['tag'], $tagValue)) {
                $tagValue[$ht['tag']] = $ht['tag'];
            }
            if (!array_key_exists($ht['tag'], $ptag)) {
                $list = [];
                $ptag[$ht['tag']] = [];
                $list['ptag'] = $ht['ptag'];
                $list['id'] = $ht['id'];
                $list['tag'] = $ht['tag'];
                array_push($ptag[$ht['tag']], $list);
            }
        }
        //新字典
        $newTagValue = [];
        foreach ($ptag as $k => &$v) {
            foreach ($v as &$val) {
                if (array_key_exists($k, $tagValue)) {
                    $val['value'] = $tagValue[$k];
                }
                array_push($newTagValue, $val);
            }
        }
        return $newTagValue;
    }

    public function tagValue($relations, $dictTag)
    {
        $tagPtag = [];
        foreach ($relations as $relation) {
            if (! isset($tagPtag[$relation['tag']])) {
                $tagPtag[$relation['tag']] = [];
            }
            array_push($tagPtag[$relation['tag']], $relation['ptag']);
        }
        $tagValue = [];  //tag-value字典
        foreach ($tagPtag as $tp => $tv) {
            $tagList = [];
            $this->formateTag($tp, $tagList, $tagPtag);
            array_reverse($tagList);
            $tagValue[$tp] = implode(',', $tagList);
        }
        foreach ($dictTag as $ht) {
            if (!array_key_exists($ht['tag'], $tagValue)) {
                $tagValue[$ht['tag']] = $ht['tag'];
            }
        }

        return $tagValue;
    }

    public function tagSubDictbak($relations, $tags, &$subTags, $ptag = '')
    {
        foreach ($tags as $tag) {
            $subtag = array_filter(
                $relations,
                function ($item) use ($tag) {
                    return $item['ptag'] == $tag['tag'];
                }
            );
            if (!empty($subtag)) {
                if (!isset($subTags[$tag['tag']])) {
                    $subTags[$tag['tag']] = array_column($subtag, 'tag');
                } else {
                    $tmpTags = array_merge(
                        $subTags[$tag['tag']],
                        array_column($subtag, 'tag')
                    );
                    $subTags[$tag['tag']] = $tmpTags;
                }
                $this->tagSubDict($relations, $subtag, $subTags, $tag['tag']);
            } else {
                $subTags[$tag['tag']] = [];
            }
        }
        return $subTags;
    }

    public function formateTag($tag, &$tagList, $tagPtag)
    {
        if (! in_array($tag, $tagList)) {
            array_push($tagList, $tag);
        }
        if (array_key_exists($tag, $tagPtag)) {
            $pTags = $tagPtag[$tag];
            foreach ($pTags as $pTag) {
                $this->formateTag($pTag, $tagList, $tagPtag);
            }
        }
        return $tagList;
    }

    public function generateTree($items, $parent_tag = '')
    {
        $tree = array();
        foreach ($items as $key => $item) {
            if ($item['ptag'] == $parent_tag) {
                $tree[$key] = $item;
                $tree[$key]['subtags'] = $this->generateTree($items, $item['tag']);
            }
        }
        $tree = array_values($tree);
        foreach ($tree as &$value) {
            $value['subtags'] = array_values($value['subtags']);
        }

        return $tree;
    }

    /**
     * 标签政策法规报道列表
     *
     * @param string $tag 标签名
     * @param int $page
     * @param int $pageSize
     * @return mixed
     */
    public function getPolicyReportList($tag, $page = 1, $pageSize = 10)
    {
        $query = \App\Models\Company\MediaReport::whereHas('tagPolicyRelations', function ($query) use ($tag) {
            $query->where('tag', '=', $tag);
        });

        $data = $query->with('auditor')
            ->orderBy('id', 'desc')
            ->paginate($pageSize, ['*'], 'page', $page);

        return $data;
    }

    public function testGit()
    {
        if (true) {
        }
    }

   public function testGit(){
     if(true){
}
}



}
