<?php

namespace atk4\audit;

class Controller {

    use \atk4\core\InitializerTrait {
        init as _init;
    }
    use \atk4\core\TrackableTrait;

    public $first_audit_log;
    public $audit_log_stack = [];

    public $record_time_taken = true;

    public $audit_model;

    public $custom_action = null;
    public $custom_fields = [];

    public function __construct($a = null, $options = [])
    {
        $this->audit_model = $a ?: $a = new model\AuditLog();

        foreach ($options as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * Will set up specified model to be logged
     */
    public function setUp(\atk4\data\Model $m)
    {
        $m->addHook('beforeSave,beforeDelete', $this, null, -100);
        $m->addHook('afterSave,afterDelete', $this, null, 100);
        $m->addRef('AuditLog', function($m) {
            $a = isset($m->audit_model) ? clone $m->audit_model : clone $this->audit_model;
            if (!$a->persistence) {
                $m->persistence->add($a);
            }

            $a->addCondition('model', get_class($m));
            if ($m->loaded()) {
                $a->addCondition('model_id', $m->id);
            }

            return $a;
        });

        if (!$m->hasMethod('log')) {
            $m->addMethod('log', [$this, 'customLog']);
        }

        $m->audit_log_controller = $this;
    }

    public function init()
    {
        $this->_init();

        $this->setUp($this->owner);
    }

    public function push(\atk4\data\Model $m, $action)
    {
        if (isset($m->audit_model)) {
            $a = clone $m->audit_model;
        } else {
            $a = clone $this->audit_model;
        }
        $m->persistence->add($a);

        if ($this->custom_action) {
            $action = $this->custom_action;
            $this->custom_action = null;
        }

        $a['ts'] = new \DateTime();
        $a['model'] = get_class($m);
        $a['model_id'] = $m->id;
        $a['action'] = $action;

        if ($this->custom_fields) {
            $a->set($this->custom_fields);
            $this->custom_fields = [];
        }

        if (!$this->first_audit_log) {
            $this->first_audit_log = $a;
        }

        if ($this->audit_log_stack) {
            $a['initiator_audit_log_id'] = $this->audit_log_stack[0]->id;
        }

        // save the initial action
        $a->save();

        $a->start_mt = (float)microtime();
        $m->audit_log = $a;

        array_unshift($this->audit_log_stack, $a);

        return $a;
    }

    public function pull(\atk4\data\Model $m)
    {
        $a = array_shift($this->audit_log_stack);

        unset($m->audit_log);

        if ($this->record_time_taken) {
            $a['time_taken'] = (float)microtime() - $a->start_mt;
        }

        return $a;
    }

    public function getDiffs(\atk4\data\Model $m)
    {
        $diff = [];
        foreach ($m->dirty as $key => $original) {

            $f = $m->hasElement($key);
            
            // don't log fields if no_audit=true is set
            if($f && isset($f->no_audit) && $f->no_audit) {
                continue;
            }

            // don't log DSQL expressions because they can be recursive and we can't store them
            if ($original instanceof \atk4\dsql\Expression || $m[$key] instanceof \atk4\dsql\Expression) {
                continue;
            }

            $diff[$key] = [$original, $m[$key]];
        }

        return $diff;
    }

    public function customLog(\atk4\data\Model $m, $action, $descr = null, $fields = [])
    {
        $a = $this->push($m, $action);
        if (!$descr){
            if ($m->hasElement($m->title_field)) {
                $descr = $action.' '.$m[$m->title_field].': ';
            } else {
                $descr = $action;
            }
        }

        $a['descr'] = $descr;

        if ($fields) {
            $a->set($fields);
        }

        $this->pull($m)->save();
    }

    public function beforeSave(\atk4\data\Model $m)
    {
        if(!$m->loaded()) {
            $a = $this->push($m, $action = 'create');
        } else {
            $a = $this->push($m, $action = 'update');
        }
        $a['request_diff'] = $this->getDiffs($m);

        if(!$a['descr'] && $m->loaded()) {

            if ($m->hasElement($m->title_field)) {
                $descr = $action.' '.$m[$m->title_field].':';
            } else {
                $descr = $action;
            }

            $a['descr'] = $a->hasMethod('getDescr') ?
                $a->getDescr() : $descr.' '.$this->getDescr($a['request_diff'], $m);
        }
    }

    public function afterSave(\atk4\data\Model $m)
    {
        $a = $this->pull($m);
        $action = 'save';

        if ($a['model_id'] === null) {
            // new record
            $a['reactive_diff'] = $m->get();
            $a['model_id'] = $m->id;

            // fill missing description for new record
            if(!$a['descr'] && $m->loaded()) {

                if ($m->hasElement($m->title_field)) {
                    $descr = $action.' '.$m[$m->title_field].':';
                } else {
                    $descr = $action;
                }

                $a['descr'] = $a->hasMethod('getDescr') ?
                    $a->getDescr() : $descr.' '.$this->getDescr($a['request_diff'], $m);
            }


        } else {
            $d = $this->getDiffs($m);
            foreach($d as $f=>list($f0,$f1)) {
                if(isset($d[$f]) &&
                    isset($a['request_diff'][$f][1]) &&
                    $a['request_diff'][$f][1] === $f1) {
                    unset($d[$f]);
                }
            }
            $a['reactive_diff'] = $d;

            if ($a['reactive_diff']) {
                $x = $a['reactive_diff'];

                $a['descr'].= ' (resulted in '.$this->getDescr($a['reactive_diff'], $m).')';
            }
        }
        
        $a->save();
    }

    public function beforeDelete(\atk4\data\Model $m)
    {
        $a = $this->push($m, 'delete');
        if ($m->only_fields) {
            $id = $m->id;
            $m = $m->newInstance()->load($id); // we need all fields
        }
        $a['request_diff'] = $m->get();
        $a['descr'] = 'delete id='.$m->id;
        if ($m->title_field && $m->hasElement($m->title_field)) {
            $a['descr'] .= ' ('.$m[$m->title_field].')';
        }
    }

    public function afterDelete(\atk4\data\Model $m)
    {
        $this->pull($m)->save();
    }

    /** 
     * Credit to mpen: http://stackoverflow.com/a/27368848/204819
     */
    protected function canBeString($var) {
        return $var === null || is_scalar($var) || is_callable([$var, '__toString']);
    }

    public function getDescr($diff, \atk4\data\Model $m)
    {
        if (!$diff) return 'no changes';
        $t = [];
        foreach ($diff as $key=>list($from, $to)) {
            $from = $m->persistence->typecastSaveField($m->getElement($key), $from);
            $to = $m->persistence->typecastSaveField($m->getElement($key), $to);

            if(!$this->canBeString($from) || ! $this->canBeString($to)) {
                throw new \atk4\core\Exception([
                    'Unable to typecast value for storing',
                    'field'=>$key,
                    'from'=>$from,
                    'to'=>$to,
                ]);
            }

            $t[] = $key.'='.$to;
        }

        return join(', ', $t);
    }
}
