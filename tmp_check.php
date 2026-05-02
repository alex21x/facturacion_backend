$rows = DB::select("SELECT table_schema, table_name FROM information_schema.tables WHERE table_schema IN ('sales','restaurant','inventory','core') ORDER BY table_schema, table_name");
foreach($rows as $r) echo $r->table_schema.'.'.$r->table_name.PHP_EOL;
