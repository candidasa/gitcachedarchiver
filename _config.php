<?php

MessageQueue::add_interface("default", array(
		"queues" => "/.*/",
		"implementation" => "SimpleDBMQ",
		"encoding" => "php_serialize",
		"send" => array(
			"onShutdown" => "all"
		),
		"delivery" => array(
			"onerror" => array(
					"log"
			)
		)));

		/* Uncomment these lines to turn on error logging */
		/*
		 $errorLog = '../assets/.errorlog/';
		if (!is_dir($errorLog)) mkdir($errorLog);
		SS_Log::add_writer(new SS_LogFileWriter($errorLog."errors.log"),SS_Log::ERR);
		SS_Log::add_writer(new SS_LogFileWriter($errorLog."errors.log"),SS_Log::WARN);
		SS_Log::add_writer(new SS_LogFileWriter($errorLog."errors.log"),SS_Log::NOTICE);
		 */
?>