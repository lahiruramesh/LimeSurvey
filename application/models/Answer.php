<?php if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}
/*
 * LimeSurvey
 * Copyright (C) 2007-2017 The LimeSurvey Project Team / Carsten Schmitz
 * All rights reserved.
 * License: GNU/GPL License v2 or later, see LICENSE.php
 * LimeSurvey is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See COPYRIGHT.php for copyright notices and details.
 *
 */

/**
 * Class Answer
 * @property integer $aid PK
 * @property integer $qid Question id
 * @property string $code Answer code
 * @property string $answer Answer text
 * @property integer $sortorder Answer sort order
 * @property integer $assessment_value
 * @property integer $scale_id
 *
 * @property Question $questions
 * @property Question $groups
 * @property AnswerL10n[] $answerL10ns
 */
class Answer extends LSActiveRecord
{
    private $oldCode;
    private $oldQid;
    private $oldScaleId;
    
    /**
     * @inheritdoc
     * @return Answer
     */
    public static function model($class = __CLASS__)
    {
        /** @var self $model */
        $model = parent::model($class);
        return $model;
    }

    /** @inheritdoc */
    public function tableName()
    {
        return '{{answers}}';
    }

    /** @inheritdoc */
    public function primaryKey()
    {
        return 'aid';
    }

    /** @inheritdoc */
    public function relations()
    {
        $alias = $this->getTableAlias();
        return array(
            'question' => array(self::BELONGS_TO, 'Question', '',
                'on' => "$alias.qid = question.qid",
            ),
            'group' => array(self::BELONGS_TO, 'QuestionGroup', '', 'through' => 'question',
                'on' => 'question.gid = group.gid'
            ),
            'answerL10ns' => array(self::HAS_MANY, 'AnswerL10n', 'aid', 'together' => true),
            'questionl10ns' => array(self::HAS_MANY, 'QuestionL10n', 'qid', 'together' => true)
            
        );
    }

    /** @inheritdoc */
    public function rules()
    {
        return array(
            array('qid', 'numerical', 'integerOnly'=>true),
            array('code', 'length', 'min' => 1, 'max'=>5),
            // Unicity of key
            array(
                'code',
                'checkUniqueness',
                'message' => gT('Answer codes must be unique by question.')
            ),
            array('sortorder', 'numerical', 'integerOnly'=>true, 'allowEmpty'=>true),
            array('assessment_value', 'numerical', 'integerOnly'=>true, 'allowEmpty'=>true),
            array('scale_id', 'numerical', 'integerOnly'=>true, 'allowEmpty'=>true),
        );
    }

    /**
     * @param integer $qid
     * @return CDbDataReader
     */
    public function getAnswers($qid)
    {
        // TODO get via Question relations
        return Yii::app()->db->createCommand()
            ->select()
            ->from(self::tableName())
            ->where(array('and', 'qid='.$qid))
            ->order('code asc')
            ->query();
    }

    public function checkUniqueness($attribute, $params)
    {
        if($this->code !== $this->oldCode || $this->qid !== $this->oldQid || $this->scale_id !== $this->oldScaleId)
        {
            $model = self::model()->find('code = ? AND qid = ? AND scale_id = ?', array($this->code, $this->qid, $this->scale_id));
            if($model != null)
                $this->addError('code','Answer codes must be unique by question');
        }   
    }

    protected function afterFind()
    {
        parent::afterFind();
        $this->oldCode = $this->code;
        $this->oldQid = $this->qid;
        $this->oldScaleId = $this->scale_id;
    }

    /**
     * Return the key=>value answer for a given $qid
     *
     * @staticvar array $answerCache
     * @param integer $qid
     * @param string $code
     * @param string $sLanguage
     * @param integer $iScaleID
     * @return string|null The answer text
     */
    public function getAnswerFromCode($qid, $code, $sLanguage, $iScaleID = 0)
    {
        static $answerCache = array();

        if (array_key_exists($qid, $answerCache)
                && array_key_exists($code, $answerCache[$qid])
                && array_key_exists($sLanguage, $answerCache[$qid][$code])
                && array_key_exists($iScaleID, $answerCache[$qid][$code][$sLanguage])) {
            // We have a hit :)
            return $answerCache[$qid][$code][$sLanguage][$iScaleID];
        } else {
            $aAnswer = Answer::model()->findByAttributes(array('qid'=>$qid, 'code'=>$code, 'scale_id'=>$iScaleID));
            if (is_null($aAnswer)) {
                return null;
            }
            $answerCache[$qid][$code][$sLanguage][$iScaleID] = $aAnswer->answerL10ns[$sLanguage]->answer;
            return $answerCache[$qid][$code][$sLanguage][$iScaleID];
        }
    }

    /**
     * @param integer $newsid
     * @param integer $oldsid
     * @return static[]
     */
    public function oldNewInsertansTags($newsid, $oldsid)
    {
        $criteria = new CDbCriteria;
        $criteria->compare('question.sid', $newsid);
        $criteria->with = ['answerL10ns'=>array('condition'=>"answer like '%{INSERTANS::{$oldsid}X%'"), 'question'];
        return $this->findAll($criteria);
    }

    /**
     * @param array $data
     * @param bool|mixed $condition
     * @return int
     */
    public function updateRecord($data, $condition = false)
    {
        return Yii::app()->db->createCommand()->update(self::tableName(), $data, $condition ? $condition : '');
    }

    /**
     * @param array $data
     * @return boolean|null
     * @deprecated at 2018-01-29 use $model->attributes = $data && $model->save()
     *
     */
    public function insertRecords($data)
    {
        $oRecord = new self;
        foreach ($data as $k => $v) {
            $oRecord->$k = $v;
        }
        if ($oRecord->validate()) {
            return $oRecord->save();
        }
        Yii::log(\CVarDumper::dumpAsString($oRecord->getErrors()), 'warning', 'application.models.Answer.insertRecords');
        return null;
    }

    /**
     * Updates sort order of answers inside a question
     *
     * @static
     * @access public
     * @param int $qid
     * @return void
     */
    public static function updateSortOrder($qid)
    {
        $data = self::model()->findAllByAttributes(array('qid' => $qid), array('order' => 'sortorder asc'));
        $position = 0;

        foreach ($data as $row) {
            $row->sortorder = $position++;
            $row->save();
        }
    }

    /**
     * @param integer $surveyid
     * @param string $lang
     * @param bool $return_query
     * @return array|CDbCommand
     * @deprecated since 2018-02-05 its not working also (the language change)
     */
    public function getAnswerQuery($surveyid, $lang, $return_query = true)
    {
        $query = Yii::app()->db->createCommand();
        $query->select("{{answers}}.*, {{questions}}.gid");
        $query->from("{{answers}}, {{questions}}");
        $query->where("{{questions}}.sid = :surveyid AND {{questions}}.qid = {{answers}}.qid AND {{questions}}.language = {{answers}}.language AND {{questions}}.language = :lang");
        $query->order('qid, code, sortorder');
        $query->bindParams(":surveyid", $surveyid, PDO::PARAM_INT);
        $query->bindParams(":lang", $lang, PDO::PARAM_STR);
        return ($return_query) ? $query->queryAll() : $query;
    }

    /**
     * @param string $fields
     * @param string $orderby
     * @param mixed $condition
     * @return array
     */
    public function getAnswersForStatistics($fields, $condition, $orderby)
    {
        return Answer::model()->findAll($condition);
    }

    /**
     * @param string $fields
     * @param string $orderby
     * @param mixed $condition
     * @return array
     */
    public function getQuestionsForStatistics($fields, $condition, $orderby)
    {

        $oAnswers = Answer::model()->with('answerL10ns')->findAll($condition);
        $arr = array();
        foreach($oAnswers as $key => $answer)
        {
            $arr[$key] = array_merge($answer->attributes, current($answer->answerL10ns)->attributes);
        }
        return $arr;
    }
    
    
}
