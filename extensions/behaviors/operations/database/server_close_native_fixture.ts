const file = "private-user-fixture.json";
export function fixtureConnect() {
  return Promise.resolve({
    async command(...args: string[]) {
      if (args[0] === "AUTH") return "OK";
      if (args[0] === "ACL" && args[1] === "GETUSER") {
        return ["flags", ["on", "nopass"]];
      }
      if (args[0] === "CLIENT" && args[1] === "ID") return 17;
      if (args[0] === "CLIENT" && args[1] === "LIST") return "id=17\n";
      if (args[0] === "ACL" && args[1] === "SETUSER") {
        const value = JSON.parse(await Deno.readTextFile(file));
        value.effects++;
        await Deno.writeTextFile(file, JSON.stringify(value));
        if (value.failEffect) throw new Error(value.material.password);
      }
      return "OK";
    },
    close() {},
  });
}
