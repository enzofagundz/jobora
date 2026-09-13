<?php

use App\Mcp\Servers\JoboraServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('jobora', JoboraServer::class);
