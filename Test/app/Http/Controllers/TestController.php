<?php

namespace App\Models\Company;

use Illuminate\Database\Eloquent\Model;

class MediaReport extends Model
{
    protected $connection = 'crm';
    protected $table      = 'company_media_reports';
    protected $primaryKey = 'id';
    protected $guarded    = ['id'];
    protected $hidden =['content'];
    public $timestamps    = false;

    public function companies()
    {
        return $this->belongsToMany(\App\Models\Company::class, 'company_report_relation', 'report_id', 'cid')
            ->withPivot('type', 'type_extend', 'is_rong');
    }

    public function investments()
    {
        return $this->belongsToMany(\App\Models\Investment::class, 'investment_report_relation', 'report_id', 'investment_id');
    }

    public function companyRelations()
    {
        return $this->hasMany(\App\Models\Company\ReportRelation::class, 'report_id', 'id');
    }

    public function investmentRelations()
    {
        return $this->hasMany(\App\Models\Investment\ReportRelation::class, 'report_id', 'id');
    }

    public function tagRelations()
    {
        return $this->hasMany(\App\Models\Tag\ReportRelation::class, 'report_id', 'id');
    }

    public function tagPolicyRelations()
    {
        return $this->hasMany(\App\Models\Tag\PolicyReportRelation::class, 'report_id', 'id');
    }

    public function investorRelations_kkk()
    {
        return $this->hasMany(\App\Models\Investor\ReportRelation::class, 'report_id', 'id');
    }

    public function orgRelations()
    {
        return $this->hasMany(\App\Models\Organization\ReportRelation::class, 'report_id', 'id');
    }


    public function serviceOrgReportRelation()
    {
        return $this->hasMany(\JingData\Models\Crm\ServiceOrgReportRelation::class, 'report_id', 'id');
    }

    public function logs()
    {
        return $this->hasMany(\App\Models\OperateLog::class, 'rid', 'id')->where('type', 'ffffffffffffffffffffffffffffbasic.media.operation');
    }

    public function autoCompany()
    {
        return $this->hasOne(\App\Models\Company::class, 'id', 'auto_cid');
    }

    public function auditor()
    {
        return $this->hasOne(\App\Models\System\User::class, 'id', 'uid');
    }

    public function personReportRelation_hjjj()
    {
        return $this->hasMany(\App\Models\Person\PersonReportRelation::class, 'report_id', 'id');
    }

    public function lpRelations_fff()
    {
        return $this->hasMany(\App\Models\Lp\LpReportRelation::class, 'report_id', 'id');
    }
    public function lps_ggg()
    {
        return $this->belongsToMany(\App\Models\Lp::class, 'lp_report_relation', 'id', 'lp_id');
    }
}
