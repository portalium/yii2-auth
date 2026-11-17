<?php

namespace portalium\auth\models;

use portalium\data\ActiveDataProvider;

/**
 * Filters for the login log page: event, method, result, a day, the user's name and the IP address.
 */
class AuthLogSearch extends AuthLog
{
    public $date;

    public function rules()
    {
        return [
            [['event', 'method', 'result'], 'string', 'max' => 32],
            ['username', 'string', 'max' => 128],
            ['ip', 'string', 'max' => 45],
            ['date', 'date', 'format' => 'php:Y-m-d'],
        ];
    }

    public function scenarios()
    {
        return \yii\base\Model::scenarios();
    }

    public function search(array $params): ActiveDataProvider
    {
        $query = AuthLog::find()->alias('l')->with('user');

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'sort' => [
                'attributes' => ['date_create' => [
                    'asc' => ['l.date_create' => SORT_ASC, 'l.id_log' => SORT_ASC],
                    'desc' => ['l.date_create' => SORT_DESC, 'l.id_log' => SORT_DESC],
                ]],
                'defaultOrder' => ['date_create' => SORT_DESC],
            ],
        ]);

        $this->load($params);
        if (!$this->validate()) {
            $query->andWhere('0=1');
            return $dataProvider;
        }

        $query->andFilterWhere([
            'l.event' => $this->event,
            'l.method' => $this->method,
            'l.result' => $this->result,
        ]);
        $query->andFilterWhere(['like', 'l.ip', $this->ip]);
        if ($this->date) {
            $query->andWhere(['between', 'l.date_create', $this->date . ' 00:00:00', $this->date . ' 23:59:59']);
        }
        if ($this->username !== null && $this->username !== '') {
            $query->joinWith('user u', false)->andWhere([
                'or',
                ['like', 'l.username', $this->username],
                ['like', 'u.username', $this->username],
                ['like', 'u.email', $this->username],
            ]);
        }

        return $dataProvider;
    }
}
