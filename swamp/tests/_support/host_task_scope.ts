/** Run a host subsystem through its shipped caller, including its vars, loops and conditions. */
import { assertEquals } from "jsr:@std/assert@1";

export function hostTasks(folder: string, names: string[]) {
    const path = `${folder}/tasks/instance-host.yml`;
    const tasks = JSON.parse(Deno.readTextFileSync(path)) as { name: string }[];
    const selected = tasks.filter((task) => names.includes(task.name));
    // A renamed, removed, duplicated or reordered caller must fail, never become an empty passing play.
    assertEquals(selected.map((task) => task.name), names);
    Deno.writeTextFileSync(path, JSON.stringify(selected));
}
