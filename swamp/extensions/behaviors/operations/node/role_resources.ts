/** Public resource shaping only. Official behaviors own every Node operation. */
import { z } from "npm:zod@4.4.3";
import { acl, egress, network, networkConfig, pool, profile, project, projectConfig } from "./ci_host_spec.ts";
import { clickhouseFiles } from "../analytics/clickhouse_files.ts";

const Argv = z.array(z.string()).min(1);
const Plan = z.union([
    z.object({
        resources: z.array(z.object({ read: Argv, marker: z.string(), create: Argv }).strict()),
        commands: z.array(z.object({ argv: Argv, stdin: z.string() }).strict()),
        checks: z.array(z.object({ argv: Argv, expected: z.string(), index: z.number().int().nonnegative() }).strict()),
    }).strict(),
    z.object({
        directories: z.array(z.string().startsWith("/")),
        files: z.array(z.object({ path: z.string().startsWith("/"), content: z.string() }).strict()),
    }).strict(),
]);

export function ciHostPlan() {
    const settings = (values: Record<string, string>) => Object.entries(values).map(([k,v]) => k+"="+v);
    const read = (collection: string, name: string, create: string[]) => ({
        read: ["timeout", "60", "incus", "query", "/1.0/"+collection+(collection.startsWith("network") ? "?project=default" : "")],
        marker: JSON.stringify("/1.0/"+collection+"/"+name),
        create: ["timeout", "60", "incus", "--project", "default", ...create],
    });
    const resources = [
        read("storage-pools",pool,["storage","create",pool,"dir"]),
        read("projects",project,["project","create",project,...Object.entries(projectConfig).filter(([k])=>k.startsWith("features.")).flatMap(([k,v])=>["-c",k+"="+v])]),
        read("network-acls",acl,["network","acl","create",acl]),
        read("networks",network,["network","create",network,"--type=bridge","ipv4.address=auto","ipv4.nat=true","ipv6.address=none"]),
    ];
    const commands = [
        {argv:["timeout","60","incus","--project","default","project","set",project,...settings(projectConfig)],stdin:""},
        {argv:["timeout","60","incus","--project","default","network","acl","edit",acl],stdin:JSON.stringify({description:"Orbit CI runner boundary",egress,ingress:[],config:{}})},
        {argv:["timeout","60","incus","--project","default","network","set",network,...settings(networkConfig)],stdin:""},
        {argv:["timeout","60","incus","--project",project,"profile","edit","default"],stdin:JSON.stringify(profile)},
    ];
    const checks = [
        ...Object.entries(projectConfig).map(([key,expected])=>({argv:["timeout","60","incus","project","get",project,key],expected})),
        ...Object.entries(networkConfig).map(([key,expected])=>({argv:["timeout","60","incus","--project","default","network","get",network,key],expected})),
        ...Object.entries(profile.config).map(([key,expected])=>({argv:["timeout","60","incus","--project",project,"profile","get","default",key],expected})),
    ].map((value,index)=>({...value,index}));
    return {resources,commands,checks};
}
export function clickhousePlan() {
    const root="/etc/orbit/analytics/clickhouse";
    return {directories:[root,root+"/config.d",root+"/users.d"],
        files:Object.entries(clickhouseFiles).map(([path,content])=>({path:root+"/"+path,content}))};
}
export const behavior = {
    type:"@nckrtl/node-role-resources",version:"2026.11.02.1",
    resources:{plan:{schema:Plan,lifetime:"1d",garbageCollection:10}},
    methods:Object.fromEntries(Object.entries({ci_host:ciHostPlan,clickhouse:clickhousePlan}).map(([name,plan])=>[name,{
        description:"Shape the canonical "+name+" resources for native steps",
        arguments:z.object({}).strict(),
        execute:async (_args:Record<string,never>,ctx:{writeResource(s:string,n:string,v:unknown):Promise<unknown>})=>{
            const value=Plan.parse(plan());await ctx.writeResource("plan","plan",value);return value;
        },
    }])),
};
