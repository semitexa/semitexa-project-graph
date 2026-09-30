<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Graph;

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

    // Concepts attributes point at that are not classes. They appear only as
    // placeholders: nothing declares them, edges name them.
    case Table         = 'table';
    case ConfigKey     = 'config_key';
    case Permission    = 'permission';
    case Capability    = 'capability';
    case Slot          = 'slot';
    case Constraint    = 'constraint';
    /** A target the extractor could not name (e.g. an untyped injected property). */
    case Unresolved    = 'unresolved';

    case DomainContext    = 'domain_context';
    case ExecutionFlow    = 'execution_flow';
    case EventFlow        = 'event_flow';
    case DataLifecycle    = 'data_lifecycle';
    case SystemBoundary   = 'system_boundary';
    case Hotspot          = 'hotspot';

    case JetStream        = 'jetstream';
    case NatsSubject      = 'nats_subject';
    case Consumer         = 'consumer';
    case EventSchema      = 'event_schema';
    case AggregateRoot    = 'aggregate_root';
    case ReplayPath       = 'replay_path';

    case DocNode          = 'doc_node';
    case UsageExample     = 'usage_example';
    case ArchitecturalDecision = 'architectural_decision';

    /**
     * The type of a node known only by its id — a placeholder an edge points
     * at. Every placeholder used to be typed "class", so config keys, tables,
     * permissions and routes counted as classes in every class query.
     */
    public static function forPlaceholderId(string $id): self
    {
        $prefix = strstr($id, ':', true);

        return match ($prefix) {
            'class'        => self::Class_,
            'route'        => self::Route,
            'table'        => self::Table,
            'config'       => self::ConfigKey,
            'permission'   => self::Permission,
            'capability'   => self::Capability,
            'slot'         => self::Slot,
            'constraint'   => self::Constraint,
            'pipeline'     => self::PipelinePhase,
            'auth'         => self::AuthHandler,
            'flow'         => self::ExecutionFlow,
            'event_flow'   => self::EventFlow,
            'domain'       => self::DomainContext,
            'lifecycle'    => self::DataLifecycle,
            'boundary'     => self::SystemBoundary,
            'hotspot'      => self::Hotspot,
            'stream'       => self::JetStream,
            'subject'      => self::NatsSubject,
            'consumer'     => self::Consumer,
            'schema'       => self::EventSchema,
            'aggregate'    => self::AggregateRoot,
            'replay'       => self::ReplayPath,
            'doc'          => self::DocNode,
            'example'      => self::UsageExample,
            'adr'          => self::ArchitecturalDecision,
            'module'       => self::Module,
            'file'         => self::File,
            'ns'           => self::Namespace_,
            'method'       => self::Method,
            'prop'         => self::Property,
            default        => self::Unresolved,
        };
    }
}
