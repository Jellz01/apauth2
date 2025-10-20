sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    
    server   = "mysql_server"
    login    = "radius"
    password = "dalodbpass"
    radius_db = "radius"
    
    read_clients = yes
    connect_failure_retry_delay = 60
    
    # Add your other SQL configuration here
}