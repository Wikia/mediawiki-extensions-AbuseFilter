-- Rename filter_timestamp_full index
ALTER TABLE /*_*/abuse_filter_log
	DROP INDEX /*i*/filter_timestamp_full;
CREATE INDEX /*i*/afl_filter_timestamp_full ON /*$wgDBprefix*/abuse_filter_log (afl_global,afl_filter_id,afl_timestamp);
