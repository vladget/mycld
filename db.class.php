<?php

/**
 * mycld class
 * @package mycld
 * @version x.x.2
 * @author ?
 */
class db extends mycld {

    var $service = 'db';
    var $logger = null;
    var $config = null;
    var $record = array();
    var $PEAR_LOG_ERRor = "";
    var $PEAR_LOG_ERRno = 0;
    //table name affected by SQL query
    var $field_table = "";
    //number of rows affected by SQL query
    var $affected_rows = 0;
    var $link_id = 0;
    var $query_id = 0;
    var $connect_when_query = false;
    var $set_utf8 = false;

    function __construct() {
        $this->logger = self::getLogger($this->service);
        $this->config = self::getConfig($this->service);
        $this->config = $this->config[$this->service]; // just remove section which has been parsed
    }

    function connect_when_query() {
        $this->connect_when_query = true;
    }

    function connect($new_link = false) {
        if ($this->config['pconnect']) {
            if ($new_link && !empty($this->link_id))
                @mysql_close($this->link_id);
            $this->link_id = mysql_pconnect($this->config['host'], $this->config['user'], $this->config['passwd']);
        }else {
            $this->link_id = mysql_connect($this->config['host'], $this->config['user'], $this->config['passwd'], $new_link);
        }

        if (!$this->link_id) {//open failed
            $this->logger->Log('Could not connect to server: ' . $this->config['host'], PEAR_LOG_ERR);
        }

        if (!mysql_select_db($this->config['dbname'], $this->link_id)) {//no database
            $this->logger->Log('Could not open database: ' . $this->config['dbname'], PEAR_LOG_ERR);
        }

        if ($this->set_utf8)
            $this->query("set names 'utf8'");

        // unset the data so it can't be dumped
        if (true) {
            $this->config['host'] = '';
            $this->config['user'] = '';
            $this->config['passwd'] = '';
            $this->config['dbname'] = '';
        }
    }

    function close() {
        if (!mysql_close($this->link_id)) {
            $this->logger->Log('Connection close failed.', PEAR_LOG_ERR);
        }
    }

    function escape($string) {
        return mysql_real_escape_string($string);
    }

    function query($sql, $type = '') {
        if ($this->connect_when_query) {
            $this->connect_when_query = false;
            $this->connect();
        }


        if ($type == 'UNBUFFERED')
            $this->query_id = mysql_unbuffered_query($query_string, $this->link_id);
        else
            $this->query_id = mysql_query($sql, $this->link_id);

        if (!$this->query_id) {
            $this->logger->Log('MySQL Query fail: ' . $sql, PEAR_LOG_ERR);
        }
        return $this->query_id;
    }

    function fetch_array($query_id = -1) {
        // retrieve row
        if ($query_id != -1) {
            $this->query_id = $query_id;
        }

        if (isset($this->query_id)) {
            $this->record = mysql_fetch_assoc($this->query_id);
        } else {
            $this->logger->Log('Invalid query_id: ' . $this->query_id . ' Records could not be fetched.', PEAR_LOG_ERR);
        }
        return $this->record;
    }

    function fetch_object($query_id = -1) {
        // retrieve row
        if ($query_id != -1) {
            $this->query_id = $query_id;
        }

        if (isset($this->query_id)) {
            $this->record = mysql_fetch_object($this->query_id);
        } else {
            $this->logger->Log('Invalid query_id: ' . $this->query_id . ' Records could not be fetched.', PEAR_LOG_ERR);
        }
        return $this->record;
    }

    function row($sql) {
        $query_id = $this->query($sql);
        $out = $this->fetch_object($query_id, $sql);
        $this->free_result($query_id);
        return $out;
    }

    function result($sql) {
        $query_id = $this->query($sql);
        $out = array();

        while ($row = $this->fetch_object($query_id, $sql)) {
            $out[] = $row;
        }

        $this->free_result($query_id);
        return $out;
    }

    function result_array($sql) {
        $query_id = $this->query($sql);
        $out = array();

        while ($row = $this->fetch_array($query_id, $sql)) {
            $out[] = $row;
        }

        $this->free_result($query_id);
        return $out;
    }

    function free_result($query_id = -1) {
        if ($query_id != -1) {
            $this->query_id = $query_id;
        }
        if (!@mysql_free_result($this->query_id)) {
            $this->logger->Log('Result ID: ' . $this->query_id . 'could not be freed.', PEAR_LOG_ERR);
        }
    }

    function row_array($query_string) {
        $query_id = $this->query($query_string);
        $out = $this->fetch_array($query_id);
        $this->free_result($query_id);
        return $out;
    }

    function query_update($table, $data, $where = '1') {
        $q = "UPDATE `" . $this->pre . $table . "` SET ";

        foreach ($data as $key => $val) {
            if (strtolower($val) == 'null')
                $q.= "`$key` = NULL, ";
            elseif (strtolower($val) == 'now()')
                $q.= "`$key` = NOW(), ";
            else
                $q.= "`$key`='" . $this->escape($val) . "', ";
        }

        $q = rtrim($q, ', ') . ' WHERE ' . $where . ';';

        return $this->query($q);
    }

    function query_insert($table, $data) {
        $q = "INSERT INTO `" . $this->pre . $table . "` ";
        $v = '';
        $n = '';

        foreach ($data as $key => $val) {
            $n.="`$key`, ";
            if (strtolower($val) == 'null')
                $v.="NULL, ";
            elseif (strtolower($val) == 'now()')
                $v.="NOW(), ";
            else
                $v.= "'" . $this->escape($val) . "', ";
        }

        $q .= "(" . rtrim($n, ', ') . ") VALUES (" . rtrim($v, ', ') . ");";

        if ($this->query($q)) {
            //$this->free_result();
            return mysql_insert_id();
        }
        else
            return false;
    }

    function affected_rows() {
        $this->affected_rows = mysql_affected_rows($this->link_id);
        return $this->affected_rows;
    }

    function insert_id() {
        return mysql_insert_id($this->link_id);
    }

}

