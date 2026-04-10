<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Graph;

enum NodeType: string
{
    case File          = 'file';
    case Namespace_    = 'namespace';
    case Module        = 'module';
    case Class_        = 'class';
    case Interface_    = 'interface';
    case Trait_        = 'trait';
    case Enum_         = 'enum';
    case Method        = 'method';
    case Property      = 'property';
    case Constant      = 'constant';
    case EnumCase      = 'enum_case';
    case Payload       = 'payload';
    case Handler       = 'handler';
    case Resource      = 'resource';
    case Service       = 'service';
    case EventListener = 'event_listener';
    case Event         = 'event';
    case Command       = 'command';
    case Component     = 'component';
    case Entity        = 'entity';
    case Repository    = 'repository';
    case Job           = 'job';
    case Workflow      = 'workflow';
    case AiSkill       = 'ai_skill';
    case Contract      = 'contract';
    case Route         = 'route';
    case PipelinePhase = 'pipeline_phase';
    case SlotHandler   = 'slot_handler';
    case AuthHandler   = 'auth_handler';
    case DataProvider  = 'data_provider';
}
